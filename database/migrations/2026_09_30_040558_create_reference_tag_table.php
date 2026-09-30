<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reference_tag', function (Blueprint $table) {
            $table->foreignId('reference_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->decimal('confidence', 4, 3)->nullable();

            $table->primary(['reference_id', 'tag_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reference_tag');
    }
};
