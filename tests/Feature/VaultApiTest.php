<?php

declare(strict_types=1);

use App\Jobs\ClassifyReference;
use App\Models\Reference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('vault');
    Queue::fake();
});

it('lista los proveedores con su disponibilidad', function () {
    $response = $this->getJson('/api/providers');

    $response->assertOk()
        ->assertJsonStructure([
            '*' => [
                'id', 'label', 'kind', 'description', 'available',
                'unavailableReason', 'models', 'defaultModel', 'allowCustomModel',
            ],
        ]);

    expect(collect($response->json())->pluck('id')->all())
        ->toContain('claude-cli', 'codex-cli');
});

it('guarda la imagen y encola su clasificación', function () {
    $response = $this->postJson('/api/references', [
        'image' => UploadedFile::fake()->image('captura.png', 800, 600),
        'provider_id' => 'claude-cli',
        'model' => 'sonnet',
    ]);

    $response->assertCreated()
        ->assertJsonPath('status', 'pending')
        ->assertJsonPath('providerId', 'claude-cli')
        ->assertJsonPath('model', 'sonnet');

    // La ruta en disco es lo que los prompts necesitan: no puede faltar.
    expect($response->json('filePath'))->toBeString()->toContain('images/');

    Queue::assertPushed(ClassifyReference::class);
    expect(Reference::count())->toBe(1);
});

it('no vuelve a guardar la misma imagen dos veces', function () {
    $image = UploadedFile::fake()->image('captura.png', 400, 400);

    $this->postJson('/api/references', [
        'image' => $image,
        'provider_id' => 'claude-cli',
    ])->assertCreated();

    $this->postJson('/api/references', [
        'image' => $image,
        'provider_id' => 'claude-cli',
    ])->assertCreated();

    expect(Reference::count())->toBe(1);
});

it('rechaza un archivo que no es imagen', function () {
    $this->postJson('/api/references', [
        'image' => UploadedFile::fake()->create('notas.pdf', 100, 'application/pdf'),
        'provider_id' => 'claude-cli',
    ])->assertStatus(422)->assertJsonValidationErrors('image');
});

it('rechaza un proveedor inexistente', function () {
    $this->postJson('/api/references', [
        'image' => UploadedFile::fake()->image('captura.png'),
        'provider_id' => 'gpt-inventado',
    ])->assertStatus(422)->assertJsonValidationErrors('provider_id');
});

it('reclasifica limpiando el resultado anterior', function () {
    $reference = Reference::factory()->create([
        'status' => Reference::STATUS_READY,
        'title' => 'Título viejo',
        'summary' => 'Resumen viejo',
        'provider_id' => 'claude-cli',
    ]);

    $this->postJson("/api/references/{$reference->id}/reclassify", [
        'provider_id' => 'codex-cli',
    ])->assertOk()
        ->assertJsonPath('status', 'pending')
        ->assertJsonPath('providerId', 'codex-cli')
        ->assertJsonPath('title', null)
        ->assertJsonPath('summary', null);

    Queue::assertPushed(ClassifyReference::class);
});

it('borra la referencia y sus archivos', function () {
    $this->postJson('/api/references', [
        'image' => UploadedFile::fake()->image('captura.png'),
        'provider_id' => 'claude-cli',
    ])->assertCreated();

    $reference = Reference::firstOrFail();
    Storage::disk('vault')->assertExists($reference->path);

    $this->deleteJson("/api/references/{$reference->id}")->assertNoContent();

    Storage::disk('vault')->assertMissing($reference->path);
    expect(Reference::count())->toBe(0);
});

it('expone el contrato que espera el frontend', function () {
    Reference::factory()->create();

    $this->getJson('/api/references')
        ->assertOk()
        ->assertJsonStructure([
            '*' => [
                'id', 'title', 'filename', 'imageUrl', 'thumbUrl', 'filePath',
                'width', 'height', 'bytes', 'sourceUrl', 'status', 'error',
                'providerId', 'model', 'summary', 'notes', 'tags', 'createdAt',
            ],
        ]);
});
