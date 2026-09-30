<?php

declare(strict_types=1);

use App\Jobs\ClassifyReference;
use App\Models\Reference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/** Carpeta temporal que hace de bandeja sincronizada. */
function inboxPath(): string
{
    return sys_get_temp_dir().'/datafront-inbox-test';
}

/** Deja un PNG real en la bandeja, envejecido para superar el margen. */
function dropInInbox(string $filename, int $ageSeconds = 120): string
{
    $path = inboxPath().'/'.$filename;

    $image = imagecreatetruecolor(40, 30);
    imagepng($image, $path);
    imagedestroy($image);

    touch($path, time() - $ageSeconds);

    return $path;
}

beforeEach(function () {
    Storage::fake('vault');
    Queue::fake();

    if (is_dir(inboxPath())) {
        array_map('unlink', glob(inboxPath().'/*') ?: []);
        rmdir(inboxPath());
    }
    mkdir(inboxPath(), 0777, true);

    config()->set('vault.inbox.path', inboxPath());
    // `touch` no puede retrasar el ctime, que es lo que mide el margen de
    // sincronización, así que se desactiva salvo en la prueba que lo cubre.
    config()->set('vault.inbox.settle_seconds', 0);
});

afterEach(function () {
    array_map('unlink', glob(inboxPath().'/*') ?: []);
    @rmdir(inboxPath());
});

it('reporta la carpeta y los motivos de rechazo', function () {
    $this->getJson('/api/inbox')
        ->assertOk()
        ->assertJsonPath('exists', true)
        ->assertJsonPath('path', inboxPath())
        ->assertJsonPath('rejectReasons.0.id', 'borrosa');
});

it('avisa sin romperse cuando la carpeta todavía no existe', function () {
    config()->set('vault.inbox.path', '/ruta/que/no/existe');

    $this->getJson('/api/inbox')
        ->assertOk()
        ->assertJsonPath('exists', false)
        ->assertJsonPath('items', []);
});

it('lista solo las capturas pendientes', function () {
    dropInInbox('20260929-143012__a3f9c2e1__pendiente.png');
    dropInInbox('20260928-090301__c1a8b3d9__procesada.png');
    dropInInbox('20260927-120000__d2e4f6a8__rechazada-borrosa.png');

    $response = $this->getJson('/api/inbox')->assertOk();

    expect($response->json('items'))->toHaveCount(1)
        ->and($response->json('items.0.hash'))->toBe('a3f9c2e1');
});

it('ignora los archivos que no siguen el formato del homelab', function () {
    dropInInbox('captura-suelta.png');
    file_put_contents(inboxPath().'/notas.txt', 'hola');

    $this->getJson('/api/inbox')->assertOk()->assertJsonPath('items', []);
});

it('marca como «sincronizando» lo que acaba de llegar', function () {
    config()->set('vault.inbox.settle_seconds', 60);
    // Recién escrito: Syncthing podría seguir copiándolo.
    dropInInbox('20260929-143012__a3f9c2e1__pendiente.png', ageSeconds: 0);

    $this->getJson('/api/inbox')
        ->assertOk()
        ->assertJsonPath('items.0.settling', true);
});

it('importa la captura y marca el original como procesada', function () {
    dropInInbox('20260929-143012__a3f9c2e1__pendiente.png');

    $this->postJson('/api/inbox/import', [
        'filename' => '20260929-143012__a3f9c2e1__pendiente.png',
        'provider_id' => 'claude-cli',
        'model' => 'sonnet',
        'source_url' => 'https://dribbble.com/shots/ejemplo',
    ])->assertCreated()
        ->assertJsonPath('status', 'pending')
        ->assertJsonPath('sourceUrl', 'https://dribbble.com/shots/ejemplo');

    // Fecha y hash intactos: solo cambia el estado.
    expect(file_exists(inboxPath().'/20260929-143012__a3f9c2e1__procesada.png'))->toBeTrue()
        ->and(file_exists(inboxPath().'/20260929-143012__a3f9c2e1__pendiente.png'))->toBeFalse();

    Queue::assertPushed(ClassifyReference::class);
    expect(Reference::count())->toBe(1);
});

it('rechaza dejando el motivo en el nombre', function () {
    dropInInbox('20260929-151145__b7d4e0f2__pendiente.png');

    $this->postJson('/api/inbox/reject', [
        'filename' => '20260929-151145__b7d4e0f2__pendiente.png',
        'reason' => 'irrelevante',
    ])->assertOk()
        ->assertJsonPath('filename', '20260929-151145__b7d4e0f2__rechazada-irrelevante.png');

    expect(file_exists(inboxPath().'/20260929-151145__b7d4e0f2__rechazada-irrelevante.png'))->toBeTrue();
    // Nada se borra: el homelab necesita el archivo para no recapturar.
    expect(Reference::count())->toBe(0);
});

it('no acepta un motivo de rechazo inventado', function () {
    dropInInbox('20260929-151145__b7d4e0f2__pendiente.png');

    $this->postJson('/api/inbox/reject', [
        'filename' => '20260929-151145__b7d4e0f2__pendiente.png',
        'reason' => 'no-me-gusta',
    ])->assertStatus(422)->assertJsonValidationErrors('reason');
});

it('no deja tocar un archivo que sigue sincronizándose', function () {
    config()->set('vault.inbox.settle_seconds', 60);
    dropInInbox('20260929-143012__a3f9c2e1__pendiente.png', ageSeconds: 0);

    $this->postJson('/api/inbox/import', [
        'filename' => '20260929-143012__a3f9c2e1__pendiente.png',
        'provider_id' => 'claude-cli',
    ])->assertStatus(422);
});

it('no deja salir de la carpeta con un nombre trucado', function () {
    $victim = sys_get_temp_dir().'/datafront-victima.png';
    file_put_contents($victim, 'no me toques');

    $this->postJson('/api/inbox/reject', [
        'filename' => '../datafront-victima.png',
        'reason' => 'borrosa',
    ])->assertStatus(422);

    expect(file_exists($victim))->toBeTrue();
    unlink($victim);
});

it('no reimporta algo que ya no está pendiente', function () {
    dropInInbox('20260928-090301__c1a8b3d9__procesada.png');

    $this->postJson('/api/inbox/import', [
        'filename' => '20260928-090301__c1a8b3d9__procesada.png',
        'provider_id' => 'claude-cli',
    ])->assertStatus(422);
});
