<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('references', function (Blueprint $table) {
            $table->id();

            // Archivo
            $table->string('filename');
            $table->string('path');              // relativa al disco `vault`
            $table->string('thumb_path')->nullable();
            $table->string('hash', 64)->unique(); // sha256: evita duplicados
            $table->string('mime', 64);
            $table->unsignedBigInteger('bytes');
            $table->unsignedInteger('width')->default(0);
            $table->unsignedInteger('height')->default(0);

            // Clasificación
            $table->string('status', 16)->default('pending')->index();
            $table->text('error')->nullable();
            $table->string('provider_id', 64)->nullable();
            $table->string('model', 64)->nullable();
            $table->string('title')->nullable();
            $table->text('summary')->nullable();
            $table->text('notes')->nullable();

            // Valores que la IA propuso pero no están en el vocabulario.
            // Alimentan `php artisan vault:vocab-gaps` para poder ampliarlo.
            $table->json('rejected_tags')->nullable();

            // Telemetría del motor, útil para comparar proveedores.
            $table->unsignedInteger('duration_ms')->nullable();
            $table->decimal('cost_usd', 10, 6)->nullable();

            $table->string('source_url')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('references');
    }
};
