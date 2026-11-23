<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Varias personas cuentan el mismo producto en lugares distintos de la tienda (una el
 * mostrador, otra la cámara de frío): cada una guarda su propio conteo y lo contado del
 * producto es la suma. `almacen_detalles` queda como el total por producto (lo que se
 * aplica al stock) y esta tabla guarda lo que aportó cada usuario. Los lotes contados
 * cuelgan del conteo del usuario que los cargó.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('almacen_detalle_usuarios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('almacen_detalle_id')->constrained('almacen_detalles')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('usuario_nombre');
            $table->decimal('cantidad', 12, 3)->default(0);
            $table->string('observacion')->nullable();
            $table->timestamps();
            $table->unique(['almacen_detalle_id', 'user_id']);
        });

        Schema::table('almacen_detalle_conteos', function (Blueprint $table) {
            $table->foreignId('almacen_detalle_usuario_id')->nullable()->after('almacen_detalle_id')
                ->constrained('almacen_detalle_usuarios')->cascadeOnDelete();
        });

        // Lo que ya estaba contado pasa a ser el conteo de quien lo cargó.
        DB::table('almacen_detalles')->orderBy('id')->chunkById(500, function ($detalles) {
            foreach ($detalles as $detalle) {
                $id = DB::table('almacen_detalle_usuarios')->insertGetId([
                    'almacen_detalle_id' => $detalle->id,
                    'user_id' => $detalle->user_id,
                    'usuario_nombre' => $detalle->usuario_nombre ?: '—',
                    'cantidad' => $detalle->cantidad,
                    'observacion' => $detalle->observacion,
                    'created_at' => $detalle->created_at,
                    'updated_at' => $detalle->updated_at,
                ]);
                DB::table('almacen_detalle_conteos')->where('almacen_detalle_id', $detalle->id)
                    ->update(['almacen_detalle_usuario_id' => $id]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('almacen_detalle_conteos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('almacen_detalle_usuario_id');
        });
        Schema::dropIfExists('almacen_detalle_usuarios');
    }
};
