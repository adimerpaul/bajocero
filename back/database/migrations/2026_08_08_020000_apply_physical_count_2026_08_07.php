<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Inventario físico de la noche del 07/08/2026: lo contado esa noche pasa a ser el
 * stock del sistema. La planilla que trajo la tienda está en
 * database/data/bajocero_stock_2026_08_07.json (código + cantidad contada).
 *
 * Los productos que no figuran en la planilla se quedan como están. Los lotes sólo
 * se tocan cuando el producto ya tenía: se descuenta por FIFO de vencimiento lo que
 * sobra o se abre un lote de ajuste por lo que falta, para que la suma de los lotes
 * siga coincidiendo con el stock del producto.
 */
return new class extends Migration
{
    private const LOTE = 'INVENTARIO 07-08-2026';

    public function up(): void
    {
        $path = database_path('data/bajocero_stock_2026_08_07.json');
        if (! is_file($path)) {
            throw new RuntimeException('No se encontró el conteo físico del 07/08/2026.');
        }

        $counted = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR)['products'] ?? [];
        if ($counted === []) {
            throw new RuntimeException('El conteo físico del 07/08/2026 no tiene productos.');
        }

        $updated = 0;
        $unchanged = 0;
        $missing = 0;

        DB::transaction(function () use ($counted, &$updated, &$unchanged, &$missing) {
            $products = DB::table('productos')->select('id', 'codigo', 'codigo_barras', 'stock_inicial')->get();
            $byCode = $products->keyBy(fn ($product) => mb_strtoupper(trim((string) $product->codigo)));
            $byBarcode = $products->filter(fn ($product) => trim((string) $product->codigo_barras) !== '')
                ->keyBy(fn ($product) => mb_strtoupper(trim((string) $product->codigo_barras)));
            $lotTotals = DB::table('lotes')->selectRaw('producto_id, SUM(cantidad_disponible) as total')
                ->groupBy('producto_id')->pluck('total', 'producto_id');

            foreach ($counted as $row) {
                $code = mb_strtoupper(trim((string) ($row['codigo'] ?? '')));
                $product = $byCode[$code] ?? $byBarcode[$code] ?? null;
                if (! $product) {
                    $missing++;

                    continue;
                }

                $stock = max(0, round((float) ($row['stock'] ?? 0), 3));
                if (abs($stock - round((float) $product->stock_inicial, 3)) <= 0.0001) {
                    $unchanged++;

                    continue;
                }

                if ($lotTotals->has($product->id)) {
                    $this->syncLots((int) $product->id, $stock, round((float) $lotTotals[$product->id], 3));
                }
                DB::table('productos')->where('id', $product->id)
                    ->update(['stock_inicial' => $stock, 'updated_at' => now()]);
                $updated++;
            }
        });

        echo "Inventario 07/08/2026: {$updated} productos actualizados, {$unchanged} ya coincidían, "
            ."{$missing} códigos de la planilla no existen en la base.".PHP_EOL;
    }

    public function down(): void
    {
        // Un conteo físico no se deshace: no queda registro del stock anterior a la revisión.
    }

    /** Deja la suma de los lotes del producto igual a lo contado. */
    private function syncLots(int $productId, float $counted, float $available): void
    {
        $difference = round($counted - $available, 3);
        if (abs($difference) <= 0.0001) {
            return;
        }

        if ($difference > 0) {
            DB::table('lotes')->insert([
                'producto_id' => $productId,
                'lote' => self::LOTE,
                'fecha_vencimiento' => null,
                'cantidad_inicial' => $difference,
                'cantidad_disponible' => $difference,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return;
        }

        $remaining = abs($difference);
        $lots = DB::table('lotes')->where('producto_id', $productId)->where('cantidad_disponible', '>', 0)
            ->orderByRaw('fecha_vencimiento IS NULL')
            ->orderBy('fecha_vencimiento')->orderBy('id')->get();

        foreach ($lots as $lot) {
            if ($remaining <= 0.0001) {
                break;
            }
            $stored = round((float) $lot->cantidad_disponible, 3);
            $taken = min($remaining, $stored);
            DB::table('lotes')->where('id', $lot->id)
                ->update(['cantidad_disponible' => round($stored - $taken, 3), 'updated_at' => now()]);
            $remaining = round($remaining - $taken, 3);
        }
    }
};
