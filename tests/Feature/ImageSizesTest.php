<?php

declare(strict_types=1);

use App\Models\Reference;
use App\Services\Vault\ImageStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('vault');
    Queue::fake();
});

function subir(int $width, int $height): Reference
{
    test()->postJson('/api/references', [
        'image' => UploadedFile::fake()->image('grande.png', $width, $height),
        'provider_id' => 'claude-cli',
    ])->assertCreated();

    return Reference::firstOrFail();
}

it('hace la miniatura con el ancho configurado', function () {
    $reference = subir(1600, 1000);

    $thumb = Storage::disk('vault')->path($reference->thumb_path);
    expect($reference->thumb_path)->toEndWith('.webp')
        ->and(getimagesize($thumb)[0])->toBe(640);
});

it('le pasa a la IA el original si cabe en los límites', function () {
    $reference = subir(1200, 800);

    expect(app(ImageStore::class)->visionPath($reference))->toBe($reference->absolutePath());
    expect(Storage::disk('vault')->exists("vision/{$reference->hash}.jpg"))->toBeFalse();
});

it('le pasa a la IA una copia reducida si el original es enorme', function () {
    config(['vault.vision.max_side' => 1000]);
    $reference = subir(2400, 1800);

    $path = app(ImageStore::class)->visionPath($reference);

    expect($path)->not->toBe($reference->absolutePath())
        ->and(getimagesize($path)[0])->toBe(1000)
        ->and(getimagesize($path)[1])->toBe(750)
        // El original se queda tal cual.
        ->and(getimagesize($reference->absolutePath())[0])->toBe(2400);
});

it('borra la copia reducida al borrar para siempre', function () {
    config(['vault.vision.max_side' => 1000]);
    $reference = subir(2400, 1800);
    app(ImageStore::class)->visionPath($reference);

    $this->deleteJson("/api/references/{$reference->id}")->assertSuccessful();
    expect(Storage::disk('vault')->exists("vision/{$reference->hash}.jpg"))->toBeTrue();

    $this->deleteJson("/api/references/{$reference->id}/forever")->assertSuccessful();
    expect(Storage::disk('vault')->exists("vision/{$reference->hash}.jpg"))->toBeFalse();
});
