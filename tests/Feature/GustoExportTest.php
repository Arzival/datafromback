<?php

declare(strict_types=1);

use App\Models\Reference;
use App\Models\Tag;
use App\Services\Vault\GustoExporter;
use App\Services\Vault\PerceptualHash;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function gustoInbox(): string
{
    return sys_get_temp_dir().'/datafront-gusto-test';
}

function gustoFile(): array
{
    return json_decode((string) file_get_contents(gustoInbox().'/gusto/boveda.json'), true);
}

beforeEach(function () {
    Storage::fake('vault');
    Queue::fake();
    exec('rm -rf '.escapeshellarg(gustoInbox()));
    mkdir(gustoInbox(), 0777, true);
    config()->set('vault.inbox.path', gustoInbox());
});

afterEach(fn () => exec('rm -rf '.escapeshellarg(gustoInbox())));

it('calcula un dHash de 16 hex, estable y distinto entre imágenes distintas', function () {
    $dir = gustoInbox();
    foreach (['a' => [255, 255, 255], 'b' => [255, 255, 255], 'c' => [0, 0, 0]] as $name => $rgb) {
        $image = imagecreatetruecolor(120, 80);
        imagefill($image, 0, 0, imagecolorallocate($image, ...$rgb));
        // Un degradado horizontal para que los bits no sean todos iguales.
        for ($x = 0; $x < 120; $x++) {
            $shade = $name === 'c' ? 255 - $x * 2 : $x * 2;
            imageline($image, $x, 0, $x, 79, imagecolorallocate($image, $shade, $shade, $shade));
        }
        imagepng($image, "{$dir}/{$name}.png");
        imagedestroy($image);
    }

    $hashes = app(PerceptualHash::class)->many(["{$dir}/a.png", "{$dir}/b.png", "{$dir}/c.png", "{$dir}/no-existe.png"]);

    expect($hashes["{$dir}/a.png"])->toMatch('/^[0-9a-f]{16}$/')
        ->and($hashes["{$dir}/a.png"])->toBe($hashes["{$dir}/b.png"])
        ->and($hashes["{$dir}/c.png"])->not->toBe($hashes["{$dir}/a.png"])
        ->and($hashes["{$dir}/no-existe.png"])->toBeNull();
});

it('guarda el dHash al subir y regenera boveda.json', function () {
    $this->postJson('/api/references', [
        'image' => UploadedFile::fake()->image('captura.png', 300, 200),
        'provider_id' => 'claude-cli',
    ])->assertCreated();

    expect(Reference::first()->dhash)->toMatch('/^[0-9a-f]{16}$/');

    $data = gustoFile();
    expect($data['items'])->toHaveCount(1)
        ->and($data['items'][0]['dhash'])->toBe(Reference::first()->dhash);
});

it('exporta el formato acordado con el homelab', function () {
    $reference = Reference::factory()->ready()->create([
        'filename' => '20261017-130852__bc50cc789e60__pendiente.jpg',
        'dhash' => '1fa7e45e6c6d0721',
    ]);
    foreach ([['screen_type', 'dashboard'], ['style', 'oscuro'], ['style', 'degradados'], ['industry', 'fintech'], ['density', 'media'], ['components', 'sidebar']] as [$category, $value]) {
        $reference->tags()->attach(Tag::firstOrCreate(compact('category', 'value')), ['confidence' => 0.9]);
    }

    app(GustoExporter::class)->refresh();
    $data = gustoFile();

    expect($data)->toHaveKeys(['actualizado', 'vocabulario', 'alias', 'items'])
        ->and($data['vocabulario'])->toHaveKeys(['tipo', 'estilo', 'layout', 'sector', 'densidad', 'componentes'])
        ->and($data['items'][0])->toMatchArray([
            'id' => (string) $reference->id,
            // Los 12 hex del nombre, porque vino de la bandeja.
            'hash' => 'bc50cc789e60',
            'tipo' => 'dashboard',
            'estilo' => ['oscuro', 'degradados'],
            'layout' => [],
            'sector' => 'fintech',
            'densidad' => 'media',
            'componentes' => ['sidebar'],
            'dhash' => '1fa7e45e6c6d0721',
        ]);
});

it('deja el hash en null para lo subido a mano', function () {
    Reference::factory()->create(['filename' => 'captura-cualquiera.png']);

    app(GustoExporter::class)->refresh();

    expect(gustoFile()['items'][0]['hash'])->toBeNull();
});

it('quita de la exportación lo que se borra de la bóveda', function () {
    $this->postJson('/api/references', [
        'image' => UploadedFile::fake()->image('captura.png', 300, 200),
        'provider_id' => 'claude-cli',
    ])->assertCreated();
    expect(gustoFile()['items'])->toHaveCount(1);

    $this->deleteJson('/api/references/'.Reference::first()->id)->assertNoContent();

    expect(gustoFile()['items'])->toBe([]);
});

it('no escribe nada si la carpeta de la bandeja no existe', function () {
    config()->set('vault.inbox.path', '/ruta/que/no/existe');

    app(GustoExporter::class)->refresh();

    expect(file_exists('/ruta/que/no/existe/gusto/boveda.json'))->toBeFalse();
});

it('no deja temporales a la vista de Syncthing', function () {
    app(GustoExporter::class)->refresh();

    expect(glob(gustoInbox().'/gusto/*'))->toBe([gustoInbox().'/gusto/boveda.json']);
});
