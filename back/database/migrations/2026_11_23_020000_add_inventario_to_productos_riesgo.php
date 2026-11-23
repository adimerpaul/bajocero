<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Al aplicar un inventario, todo lo contado con fecha de vencimiento entra solo a
 * "Productos en riesgo" (origen INVENTARIO). Se guarda de qué inventario vino.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('productos_riesgo', function (Blueprint $table) {
            $table->foreignId('almacen_id')->nullable()->after('origen')->constrained('almacenes')->nullOnDelete();
            $table->string('documento', 30)->nullable()->after('almacen_id');
        });
    }

    public function down(): void
    {
        Schema::table('productos_riesgo', function (Blueprint $table) {
            $table->dropConstrainedForeignId('almacen_id');
            $table->dropColumn('documento');
        });
    }
};
