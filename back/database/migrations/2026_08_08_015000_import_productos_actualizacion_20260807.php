<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Alta de los productos que el almacén registró en la actualización del 07/08/2026
 * y que todavía no existían en el sistema.
 *
 * El archivo original traía 2022 filas: 1825 ya existían, 12 eran productos que ya
 * están en el catálogo con otro código (mismo nombre) y por eso se dejaron fuera —
 * crearlos habría duplicado inventario— y 185 son estos, realmente nuevos.
 *
 * Corre ANTES de apply_physical_count_2026_08_07 a propósito: 184 de estos productos
 * también figuran en el conteo de esa noche, así que nacen en stock 0 y es esa migración
 * la que les asigna la cantidad contada abriendo el lote de ajuste correspondiente.
 * Así el stock del producto y la suma de sus lotes quedan cuadrados desde el primer día.
 * El único que no está en el conteo conserva la cantidad que traía la planilla.
 */
return new class extends Migration
{
    public function up(): void
    {
        $path = database_path('data/bajocero_productos_nuevos_2026_08_07.json');
        if (! is_file($path)) {
            throw new RuntimeException('No se encontró el archivo de productos nuevos del 07/08/2026.');
        }

        $productos = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        DB::transaction(function () use ($productos) {
            $now = now();
            foreach ($productos as $producto) {
                // Idempotente: si el código ya está (por una carga previa) no se toca nada.
                $existe = DB::table('productos')->where('codigo', $producto['codigo'])->exists();
                if ($existe) {
                    continue;
                }

                // La categoría del volcado puede haberse borrado entre tanto; en ese caso queda sin asignar.
                $categoriaId = $producto['categoria_id'] && DB::table('categorias')->where('id', $producto['categoria_id'])->exists()
                    ? $producto['categoria_id']
                    : null;

                // El código de barras es único: si otro producto ya lo usa, se deja vacío
                // para no romper la inserción, y se corrige a mano desde la pantalla de productos.
                $codigoBarras = $producto['codigo_barras'];
                if ($codigoBarras && DB::table('productos')->where('codigo_barras', $codigoBarras)->exists()) {
                    $codigoBarras = null;
                }

                DB::table('productos')->insert([
                    'codigo' => $producto['codigo'],
                    'codigo_barras' => $codigoBarras,
                    'nombre' => $producto['nombre'],
                    'categoria' => $producto['categoria'],
                    'categoria_id' => $categoriaId,
                    'unidad' => $producto['unidad'],
                    'precio_compra' => $producto['precio_compra'],
                    'precio_venta' => $producto['precio_venta'],
                    'stock_inicial' => $producto['stock_inicial'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        });
    }

    public function down(): void
    {
        // No se eliminan: para cuando se revierta la migración estos productos ya pueden
        // tener ventas, compras y lotes asociados.
    }
};
