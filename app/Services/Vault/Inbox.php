<?php

declare(strict_types=1);

namespace App\Services\Vault;

use App\Models\Reference;
use Illuminate\Http\UploadedFile;
use RuntimeException;

/**
 * Bandeja de entrada del homelab.
 *
 * La carpeta ES el registro: no hay base de datos del lado del homelab, y el
 * estado en el nombre del archivo es lo único que le dice que una referencia ya
 * se trabajó. Por eso aquí nunca se borra nada ni se altera la fecha o el hash:
 * solo se reescribe el tramo del estado.
 */
class Inbox
{
    /** AAAAMMDD-HHMMSS__<hash>__<estado>.<ext> */
    private const PATTERN = '/^(\d{8}-\d{6})__([a-z0-9]{4,64})__([a-z]+(?:-[a-z]+)?)\.([a-z0-9]+)$/i';

    public function __construct(private readonly ImageStore $images) {}

    public function path(): string
    {
        return rtrim((string) config('vault.inbox.path'), '/');
    }

    public function exists(): bool
    {
        return $this->path() !== '' && is_dir($this->path());
    }

    /**
     * Capturas pendientes, de la más reciente a la más antigua.
     *
     * @return array<int, array<string, mixed>>
     */
    public function pending(): array
    {
        if (! $this->exists()) {
            return [];
        }

        $extensions = config('vault.inbox.extensions');
        $settle = (int) config('vault.inbox.settle_seconds');
        $items = [];

        foreach (scandir($this->path()) ?: [] as $entry) {
            $parsed = $this->parse($entry);

            if ($parsed === null || $parsed['state'] !== 'pendiente') {
                continue;
            }

            if (! in_array(strtolower($parsed['extension']), $extensions, true)) {
                continue;
            }

            $absolute = $this->path().'/'.$entry;
            if (! is_file($absolute)) {
                continue;
            }

            [$width, $height] = $this->dimensions($absolute);

            $sidecar = $this->sidecar($absolute);

            $items[] = [
                'filename' => $entry,
                'previewUrl' => route('inbox.preview', ['filename' => $entry]),
                // Vienen del .txt hermano que escribe el homelab.
                'sourceUrl' => $sidecar['url'],
                'sourceTitle' => $sidecar['title'],
                'hash' => $parsed['hash'],
                'capturedAt' => $parsed['capturedAt'],
                'bytes' => (int) filesize($absolute),
                'width' => $width,
                'height' => $height,
                // Aún sincronizando: Syncthing puede seguir escribiéndolo.
                'settling' => $this->isSettling($absolute, $settle),
                // Ya está en la bóveda de una importación anterior.
                'alreadyInVault' => $this->alreadyInVault($absolute),
            ];
        }

        usort($items, fn ($a, $b) => strcmp($b['filename'], $a['filename']));

        return $items;
    }

    /**
     * Mete la captura en la bóveda y marca el original como procesada.
     *
     * El renombrado ocurre aunque la clasificación falle después: lo que el
     * estado registra es mi decisión de quedármela, no el resultado de la IA.
     */
    public function import(string $filename, ?string $sourceUrl = null): Reference
    {
        $absolute = $this->resolve($filename);
        $this->assertSettled($absolute);

        $upload = new UploadedFile(
            $absolute,
            $filename,
            null,
            null,
            // Se marca como «de prueba» para que Symfony no exija que venga de
            // una subida HTTP real: el archivo lo puso Syncthing.
            true,
        );

        $reference = $this->images->store(
            $upload,
            $sourceUrl ?? $this->sidecar($absolute)['url'],
        );

        $this->rename($absolute, 'procesada');

        return $reference;
    }

    /** Descarta la captura sin importarla, dejando constancia del motivo. */
    public function reject(string $filename, string $reason): string
    {
        $reasons = config('vault.inbox.reject_reasons');

        if (! array_key_exists($reason, $reasons)) {
            throw new RuntimeException("Motivo de rechazo desconocido: «{$reason}».");
        }

        return $this->rename($this->resolve($filename), "rechazada-{$reason}");
    }

    /**
     * Valida el nombre que llega del cliente y lo ata a la carpeta.
     *
     * Sin esto, un `../../` en el nombre dejaría renombrar cualquier archivo
     * de la máquina.
     */
    private function resolve(string $filename): string
    {
        if (! $this->exists()) {
            throw new RuntimeException('La carpeta de la bandeja no existe: '.$this->path());
        }

        $parsed = $this->parse($filename);

        if ($parsed === null) {
            throw new RuntimeException("«{$filename}» no sigue el formato de la bandeja.");
        }

        if ($parsed['state'] !== 'pendiente') {
            throw new RuntimeException("«{$filename}» ya está en estado {$parsed['state']}.");
        }

        $absolute = $this->path().'/'.basename($filename);
        $real = realpath($absolute);

        if ($real === false || ! is_file($real) || ! str_starts_with($real, (string) realpath($this->path()))) {
            throw new RuntimeException("«{$filename}» no está en la bandeja.");
        }

        return $real;
    }

    private function assertSettled(string $absolute): void
    {
        if ($this->isSettling($absolute, (int) config('vault.inbox.settle_seconds'))) {
            throw new RuntimeException(
                'El archivo sigue sincronizándose. Espera unos segundos e inténtalo otra vez.',
            );
        }
    }

    /**
     * ¿Acaba de aparecer en esta máquina?
     *
     * Se mide con ctime y no con mtime a propósito: Syncthing conserva el mtime
     * del emisor, así que ese valor es la hora en que el homelab hizo la
     * captura, no la de la copia local, y un archivo capturado ayer parecería
     * asentado en cuanto asoma. El ctime solo lo escribe el sistema de ficheros
     * local al crear o renombrar, que es justo lo que queremos vigilar.
     *
     * Una marca en el futuro (relojes desfasados entre las dos máquinas) se
     * trata como asentada: si no, ese archivo no se podría importar nunca.
     */
    private function isSettling(string $absolute, int $settle): bool
    {
        if ($settle <= 0) {
            return false;
        }

        $age = time() - (int) filectime($absolute);

        return $age >= 0 && $age < $settle;
    }

    /** Cambia solo el tramo del estado; fecha y hash quedan intactos. */
    private function rename(string $absolute, string $state): string
    {
        $parsed = $this->parse(basename($absolute));

        if ($parsed === null) {
            throw new RuntimeException('No se pudo interpretar el nombre del archivo.');
        }

        $target = sprintf(
            '%s/%s__%s__%s.%s',
            dirname($absolute),
            $parsed['capturedAtRaw'],
            $parsed['hash'],
            $state,
            $parsed['extension'],
        );

        if (! @rename($absolute, $target)) {
            throw new RuntimeException('No se pudo renombrar el archivo en la bandeja.');
        }

        // El .txt hermano se identifica por el nombre base completo, estado
        // incluido: si solo se renombrara la imagen dejarían de ser pareja y el
        // homelab no podría archivarlos juntos.
        $sidecar = $this->sidecarPath($absolute);

        if (is_file($sidecar)) {
            @rename($sidecar, $this->sidecarPath($target));
        }

        return basename($target);
    }

    /** @return array{capturedAtRaw:string, capturedAt:?string, hash:string, state:string, extension:string}|null */
    private function parse(string $filename): ?array
    {
        if (preg_match(self::PATTERN, $filename, $m) !== 1) {
            return null;
        }

        return [
            'capturedAtRaw' => $m[1],
            'capturedAt' => $this->toIso($m[1]),
            'hash' => $m[2],
            'state' => strtolower($m[3]),
            'extension' => $m[4],
        ];
    }

    private function toIso(string $stamp): ?string
    {
        $date = \DateTimeImmutable::createFromFormat('Ymd-His', $stamp);

        return $date === false ? null : $date->format(\DateTimeInterface::ATOM);
    }

    private function alreadyInVault(string $absolute): bool
    {
        $hash = hash_file('sha256', $absolute);

        return $hash !== false && Reference::where('hash', $hash)->exists();
    }

    /**
     * Ruta del .txt hermano: mismo nombre base, extensión .txt.
     *
     * El homelab escribe ahí la URL de origen en la primera línea y el título
     * de la página en la segunda. Es la única forma de rellenar `source_url`
     * en lo que llega automático.
     */
    private function sidecarPath(string $absolute): string
    {
        return preg_replace('/\.[^.]+$/', '', $absolute).'.txt';
    }

    /** @return array{url: ?string, title: ?string} */
    private function sidecar(string $absolute): array
    {
        $path = $this->sidecarPath($absolute);

        if (! is_file($path)) {
            return ['url' => null, 'title' => null];
        }

        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if ($lines === false) {
            return ['url' => null, 'title' => null];
        }

        $url = trim($lines[0] ?? '');
        $title = trim($lines[1] ?? '');

        return [
            // Se descarta lo que no sea una URL: el campo lo consume la UI.
            'url' => filter_var($url, FILTER_VALIDATE_URL) !== false ? $url : null,
            'title' => $title !== '' ? mb_substr($title, 0, 200) : null,
        ];
    }

    /** @return array{0:int,1:int} */
    private function dimensions(string $absolute): array
    {
        $info = @getimagesize($absolute);

        return $info === false ? [0, 0] : [(int) $info[0], (int) $info[1]];
    }
}
