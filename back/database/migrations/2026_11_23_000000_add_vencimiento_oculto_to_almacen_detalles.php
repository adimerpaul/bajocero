<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Por vencer" / "Vencidos" se arman con los lotes del último inventario aplicado.
 * Una línea se puede quitar de la lista sin borrarla: queda marcada, se manda al final
 * y se puede volver a habilitar.
 */
return new class extends Migration
{
    private array $tablas = ['almacen_detalles', 'almacen_detalle_conteos'];

    public function up(): void
    {
        foreach ($this->tablas as $tabla) {
            Schema::table($tabla, function (Blueprint $table) {
                $table->timestamp('vencimiento_oculto_at')->nullable();
                $table->string('vencimiento_oculto_por')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tablas as $tabla) {
            Schema::table($tabla, function (Blueprint $table) {
                $table->dropColumn(['vencimiento_oculto_at', 'vencimiento_oculto_por']);
            });
        }
    }
};
