<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // valor_ficha: una ficha por cada tantos Bs de compra (30 Bs y compra 90 → 3 fichas); vacío = una sola ficha.
        // impresiones: copias de cada ficha (ej. 2: una para el cliente y otra para la tienda).
        Schema::table('promociones', function (Blueprint $table) {
            $table->decimal('valor_ficha', 12, 2)->nullable()->after('monto');
            $table->unsignedTinyInteger('impresiones')->default(1)->after('valor_ficha');
        });
    }

    public function down(): void
    {
        Schema::table('promociones', function (Blueprint $table) {
            $table->dropColumn(['valor_ficha', 'impresiones']);
        });
    }
};
