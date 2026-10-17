<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('references', function (Blueprint $table) {
            // Huella perceptual (dHash de 64 bits en 16 hex). La usa el filtro
            // de gusto del homelab para no mandar casi copias de lo aceptado.
            $table->string('dhash', 16)->nullable()->after('hash');
        });
    }

    public function down(): void
    {
        Schema::table('references', function (Blueprint $table) {
            $table->dropColumn('dhash');
        });
    }
};
