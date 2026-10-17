<?php

declare(strict_types=1);

use App\Jobs\ClassifyReference;
use App\Models\Reference;
use App\Models\Tag;
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

it('borrar manda a la papelera sin tocar los archivos', function () {
    $this->postJson('/api/references', [
        'image' => UploadedFile::fake()->image('captura.png'),
        'provider_id' => 'claude-cli',
    ])->assertCreated();

    $reference = Reference::firstOrFail();

    $this->deleteJson("/api/references/{$reference->id}")->assertNoContent();

    Storage::disk('vault')->assertExists($reference->path);
    expect(Reference::count())->toBe(0)
        ->and(Reference::onlyTrashed()->count())->toBe(1);

    $this->getJson('/api/references')->assertJsonCount(0);
    $this->getJson('/api/references/trash')
        ->assertJsonCount(1)
        ->assertJsonPath('0.id', (string) $reference->id);

    // La imagen se sigue sirviendo para poder verla en la papelera.
    $this->get("/api/references/{$reference->id}/image")->assertOk();
});

it('restaura una referencia de la papelera con sus etiquetas', function () {
    $reference = Reference::factory()->create();
    $tag = Tag::create(['category' => 'style', 'value' => 'oscuro']);
    $reference->tags()->attach($tag->id);
    $reference->delete();

    $this->postJson("/api/references/{$reference->id}/restore")
        ->assertOk()
        ->assertJsonPath('deletedAt', null)
        ->assertJsonPath('tags.0.value', 'oscuro');

    expect(Reference::count())->toBe(1);
});

it('solo borra para siempre lo que está en la papelera', function () {
    $this->postJson('/api/references', [
        'image' => UploadedFile::fake()->image('captura.png'),
        'provider_id' => 'claude-cli',
    ])->assertCreated();

    $reference = Reference::firstOrFail();

    $this->deleteJson("/api/references/{$reference->id}/forever")->assertConflict();
    Storage::disk('vault')->assertExists($reference->path);

    $this->deleteJson("/api/references/{$reference->id}")->assertNoContent();
    $this->deleteJson("/api/references/{$reference->id}/forever")->assertNoContent();

    Storage::disk('vault')->assertMissing($reference->path);
    expect(Reference::withTrashed()->count())->toBe(0);
});

it('volver a subir una imagen de la papelera la restaura', function () {
    $image = UploadedFile::fake()->image('captura.png', 400, 400);

    $this->postJson('/api/references', ['image' => $image, 'provider_id' => 'claude-cli'])->assertCreated();
    $reference = Reference::firstOrFail();
    $this->deleteJson("/api/references/{$reference->id}")->assertNoContent();

    $this->postJson('/api/references', ['image' => $image, 'provider_id' => 'claude-cli'])->assertCreated()
        ->assertJsonPath('id', (string) $reference->id);

    expect(Reference::count())->toBe(1)
        ->and(Reference::withTrashed()->count())->toBe(1);
});

it('expone el contrato que espera el frontend', function () {
    Reference::factory()->create();

    $this->getJson('/api/references')
        ->assertOk()
        ->assertJsonStructure([
            '*' => [
                'id', 'title', 'filename', 'imageUrl', 'thumbUrl', 'filePath',
                'width', 'height', 'bytes', 'sourceUrl', 'status', 'error',
                'providerId', 'model', 'summary', 'notes', 'tags', 'createdAt', 'deletedAt',
            ],
        ]);
});
