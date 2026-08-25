<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cinco niveles de precio por producto.
 *
 * El precio 5 es el precio de venta al público y los anteriores bajan un 5% por nivel,
 * de modo que el 1 es el más barato (mayoreo) y el 5 el más caro.
 */
return new class extends Migration
{
    /** Porcentaje del precio de venta que corresponde a cada nivel. */
    private const ESCALA = [1 => 0.80, 2 => 0.85, 3 => 0.90, 4 => 0.95, 5 => 1.00];

    public function up(): void
    {
        if (! Schema::hasTable('productos')) {
            return;
        }

        Schema::table('productos', function (Blueprint $table) {
            $anterior = 'precio_venta';
            foreach (array_keys(self::ESCALA) as $nivel) {
                if (! Schema::hasColumn('productos', "precio_{$nivel}")) {
                    $table->decimal("precio_{$nivel}", 12, 2)->default(0)->after($anterior);
                }
                $anterior = "precio_{$nivel}";
            }
        });

        $valores = [];
        foreach (self::ESCALA as $nivel => $factor) {
            $valores["precio_{$nivel}"] = DB::raw("ROUND(precio_venta * {$factor}, 2)");
        }
        DB::table('productos')->update($valores);
    }

    public function down(): void
    {
        if (! Schema::hasTable('productos')) {
            return;
        }

        Schema::table('productos', function (Blueprint $table) {
            $columnas = array_values(array_filter(
                array_map(fn ($nivel) => "precio_{$nivel}", array_keys(self::ESCALA)),
                fn ($columna) => Schema::hasColumn('productos', $columna)
            ));
            if ($columnas !== []) {
                $table->dropColumn($columnas);
            }
        });
    }
};
