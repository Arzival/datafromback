<?php

use App\Http\Controllers\Api\InboxController;
use App\Http\Controllers\Api\ProviderController;
use App\Http\Controllers\Api\ReferenceController;
use Illuminate\Support\Facades\Route;

Route::get('/health', fn () => [
    'status' => 'ok',
    'app' => config('app.name'),
    'laravel' => app()->version(),
    'php' => PHP_VERSION,
]);

Route::get('/providers', [ProviderController::class, 'index']);

Route::prefix('references')->group(function () {
    Route::get('/', [ReferenceController::class, 'index']);
    Route::post('/', [ReferenceController::class, 'store']);
    Route::get('/{reference}', [ReferenceController::class, 'show']);
    Route::delete('/{reference}', [ReferenceController::class, 'destroy']);
    Route::post('/{reference}/reclassify', [ReferenceController::class, 'reclassify']);

    // Los archivos se sirven por ruta en vez de por enlace simbólico: la
    // bóveda es privada y así se puede controlar el acceso más adelante.
    Route::get('/{reference}/image', [ReferenceController::class, 'image'])->name('references.image');
    Route::get('/{reference}/thumb', [ReferenceController::class, 'thumb'])->name('references.thumb');
});

// Bandeja de entrada: lo que el homelab deja por Syncthing. Nada se importa
// solo; aquí únicamente se listan las capturas y se aplica mi decisión.
Route::prefix('inbox')->group(function () {
    Route::get('/', [InboxController::class, 'index']);
    Route::post('/import', [InboxController::class, 'import']);
    Route::post('/reject', [InboxController::class, 'reject']);
    Route::get('/{filename}/preview', [InboxController::class, 'preview'])
        ->name('inbox.preview')
        ->where('filename', '[A-Za-z0-9._-]+');
});
