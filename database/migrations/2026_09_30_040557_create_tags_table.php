<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->string('category', 32)->index();
            $table->string('value', 96);
            $table->timestamps();

            // Un mismo valor solo existe una vez por categoría.
            $table->unique(['category', 'value']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tags');
    }
};
