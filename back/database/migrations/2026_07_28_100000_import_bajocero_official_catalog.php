<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $catalogPath = database_path('data/bajocero_products_2026.json');
        if (! is_file($catalogPath)) {
            throw new RuntimeException('No se encontró el catálogo oficial de Bajo Cero.');
        }

        $catalog = json_decode(file_get_contents($catalogPath), true, flags: JSON_THROW_ON_ERROR);
        $products = $catalog['products'] ?? [];
        if (count($products) !== 1830) {
            throw new RuntimeException('El catálogo oficial debe contener 1830 productos únicos.');
        }

        $categoryColors = [
            'red', 'deep-orange', 'orange', 'amber', 'brown',
            'pink', 'purple', 'indigo', 'blue-grey',
        ];
        $categoryIds = [];
        $categoryNames = array_values(array_unique(array_column($products, 'categoria')));
        sort($categoryNames);

        DB::transaction(function () use ($products, $categoryNames, $categoryColors, &$categoryIds) {
            foreach ($categoryNames as $index => $name) {
                $category = DB::table('categorias')->where('nombre', $name)->first();
                if ($category) {
                    DB::table('categorias')->where('id', $category->id)->update([
                        'color' => $categoryColors[$index % count($categoryColors)],
                        'updated_at' => now(),
                        'deleted_at' => null,
                    ]);
                    $categoryIds[$name] = $category->id;
                } else {
                    $categoryIds[$name] = DB::table('categorias')->insertGetId([
                        'nombre' => $name,
                        'color' => $categoryColors[$index % count($categoryColors)],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            $hasSales = Schema::hasTable('venta_detalles') && DB::table('venta_detalles')->exists();
            if (! $hasSales) {
                DB::table('productos')->delete();
            }

            foreach (array_chunk($products, 250) as $chunk) {
                $rows = array_map(function (array $product) use ($categoryIds) {
                    return [
                        ...$product,
                        'categoria_id' => $categoryIds[$product['categoria']],
                        'foto' => null,
                        'created_at' => now(),
                        'updated_at' => now(),
                        'deleted_at' => null,
                    ];
                }, $chunk);

                if (! $hasSales) {
                    DB::table('productos')->insert($rows);
                    continue;
                }

                foreach ($rows as $row) {
                    $timestamps = [
                        'created_at' => $row['created_at'],
                        'updated_at' => $row['updated_at'],
                    ];
                    unset($row['created_at']);
                    DB::table('productos')->updateOrInsert(
                        ['codigo' => $row['codigo']],
                        [...$row, ...$timestamps]
                    );
                }
            }

            if (! $hasSales) {
                DB::table('categorias')
                    ->whereNotIn('nombre', $categoryNames)
                    ->update(['deleted_at' => now(), 'updated_at' => now()]);
            }
        });
    }

    public function down(): void
    {
        // El catálogo no se elimina para evitar pérdidas de inventario o trazabilidad.
    }
};
