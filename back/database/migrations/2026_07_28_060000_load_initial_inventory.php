<?php

use App\Models\Producto;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;

return new class extends Migration
{
    public function up(): void
    {
        $permissions = [
            'Ver Usuarios', 'Crear Usuarios', 'Editar Usuarios', 'Eliminar Usuarios',
            'Gestionar Permisos', 'Ver Productos', 'Crear Productos',
            'Editar Productos', 'Eliminar Productos',
        ];
        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
        $admin = User::firstOrCreate(['username' => 'admin'], [
            'name' => 'ADMINISTRADOR',
            'email' => 'admin@mundolac.com',
            'ci' => '00000000',
            'password' => bcrypt('admin'),
        ]);
        $admin->syncPermissions(Permission::all());

        $rows = [
            ['F01600', 'LECHE DESLACTOSADA POLVO 760 GR', 'KG', 77.1, 85, 10],
            ['F02223', 'YOGURELO ESCOLAR', 'ML', 1.1, 1.5, 1575],
            ['F05531', 'MANTEQUILLA REYNA VASITO', 'GR', 11.8, 13, 24],
            ['F01232', 'LECHE PIL ENRIQUEZIDA 800 ML', 'ML', 7.7, 8.5, 220],
            ['F02242', 'BIOGURT', 'ML', 19.1, 22, 30],
            ['F04107', 'JUGO PURA VIDA 2 LT', 'LT', 13, 15, 6],
            ['F01017', 'LECHE DESLACTOSADA 800 ML', 'ML', 9.15, 10, 159],
            ['F04143', 'JUGO PURA VIDA 500 ML', 'ML', 5.4, 7, 78],
            ['F01214', 'CHICOLAC 120 ML', 'ML', 1.11, 1.5, 1965],
            ['F04617', 'PILFRUT 175 ML', 'ML', 1.1, 1.5, 4654],
            ['F01197', 'CHICOLAC 800 ML', 'ML', 8.15, 9, 42],
            ['F01233', 'LECHE EVAPORADA CREMOSA 1 KG', 'KG', 35, 40, 48],
            ['F06534', 'LECHE DE SOYA 946 ML', 'ML', 6.3, 7.5, 10],
            ['F02042', 'YOGURE BOLSA 1000 ML', 'ML', 15, 17, 88],
            ['F01144', 'CHIQUICHOK-AVENA-FRUTILLA', 'ML', 1.75, 2.5, 480],
            ['F01550', 'LECHE EN POLVO 760 GR', 'GR', 66.5, 75, 20],
            ['F01239', 'LECHE CON CAFÉ VAINILLA/MOCA CAJITA', 'ML', 10.6, 13, 24],
            ['F01226', 'LECHE CON CAFÉ DESLACTOSADA', 'ML', 12.05, 14, 22],
            ['F01182', 'LECHE CON CAFÉ MONACO BOLSA', 'ML', 11.25, 13, 22],
            ['F02119', 'YOGUR BOTELLA 1 LT', 'ML', 17.05, 19.5, 12],
            ['F02218', 'YOGUR BONLE 2 LT', 'ML', 19.6, 22, 6],
            ['F04237', 'PILFRUT BOTELLA 2 LT', 'ML', 11.55, 13, 90],
            ['F01241', 'LECHE BONLE 800 ML', 'ML', 5.7, 6.5, 132],
            ['F02153', 'YOGUR BONLE 1 LT', 'ML', 11.7, 13, 48],
            ['F02191', 'YOGUR PIL 2 LT', 'LT', 27.9, 30, 27],
            ['F03001', 'MANTEQUILLA PIL CON SAL 200 GR', 'GR', 20.2, 23, 25],
            ['F03012', 'MANTEQUILLA PIL CON SAL 100 GR', 'GR', 11.15, 14, 25],
            ['F06543', 'SOYA BOLSITA', 'ML', 1.45, 2, 100],
            ['F05533', 'MATEQUILLA REYNA 410 GR', 'GR', 16.9, 19, 6],
            ['F05534', 'MATEQUILLA REYNA 820 GR', 'GR', 26.8, 29, 6],
            ['F01235', 'LECHE EVAPORADA CREMOSA 350 GR', 'ML', 13.5, 15.5, 48],
            ['F04106', 'JUGO PURA VIDA 2 LT', 'ML', 13, 15, 60],
            ['F02081', 'YOGUR FRUTADO VASO PEQUEÑO', 'GR', 4.15, 5.5, 40],
            ['F02248', 'YOGUR ENTERO FRUTADO', 'GR', 19.2, 22, 14],
            ['F02235', 'NUTRIBUM', 'GR', 11.4, 13, 24],
            ['F01551', 'LECHE POLVO 370 GR', 'GR', 33.2, 36, 40],
            ['F01231', 'LECHE PIL 900 ML', 'ML', 8.25, 9.5, 200],
            ['F01229', 'LECHE SABORIZADA FRUT/CHOCO', 'ML', 9.1, 10, 11],
            ['F03506', 'DULCE DE LECHE 1000 GR', 'GR', 28.6, 30, 12],
            ['F03507', 'DULCE DE LECHE 500 GR', 'GR', 15.3, 17, 24],
            ['F03521', 'LECHE CONDENSADA 200 GR', 'GR', 8.15, 10, 20],
            ['F04026', 'AGUA PURA VIDA 2LT', 'ML', 5, 6.5, 18],
            ['F04029', 'AGUA PURA VIDA 3LT', 'ML', 7, 8.5, 12],
            ['F04229', 'AGUA PURA VIDA 500 ML', 'ML', 2.6, 3.5, 12],
            ['F04025', 'JUGO PURA VIDA 300 ML', 'ML', 3.35, 4, 12],
            ['F04242', 'JUGO PURA VIDA 2 LT MOCONCHINCHI', 'ML', 14, 16, 6],
            ['F06008', 'GELATINA YELI', 'GR', 1.65, 2.5, 360],
            ['F02020', 'YOGUR BATIDITO', 'GR', 2.07, 3, 384],
            ['F01120', 'LECHE PIL CREMOSA CAJA 1 LT', 'ML', 12.05, 15, 12],
            ['F01122', 'LECHE DESLACTOSADA CAJA 1 LT', 'ML', 12.6, 15, 12],
            ['F01123', 'LECHE CHOCOLATADA 1 LT CAJA', 'LT', 12.99, 16, 12],
            ['F01201', 'LECHE PIL CAJA 1 LT', 'LT', 10.85, 14, 12],
            ['F02194', 'YOGUR GRIEGO PIL 160 GR', 'GR', 7.3, 9, 54],
            ['F01531', 'LECHE POLVO 120 GR', 'GR', 11.25, 13.5, 96],
            ['D3586', 'KEFIR SIN AZUCAR 1000 GR', 'GR', 26.7, 29, 18],
            ['D3567', 'KEFIR SIN AZUCAR VASITO', 'GR', 6.58, 7.5, 15],
            ['D2553', 'CREMA DE LECHE REPOSTERA', 'ML', 24.3, 27, 12],
            ['D3145', 'YOGUR DELIZIA 2 LT', 'ML', 26.9, 29, 18],
            ['D3538', 'VAQUITA CHOCOLATE/FRUT/AVENA', 'ML', 1.4, 2, 200],
            ['D3151', 'YOGUR PROBIOTICO 1 LT', 'ML', 18, 22, 6],
            ['D2600', 'YOGUR GRIEGO PIL 170 GR', 'GR', 7.52, 9, 30],
            ['D7704', 'AGUA SACHET 500 ML', 'ML', 0.67, 1, 160],
            ['D3549', 'LECHE DELIZIA 900 ML', 'ML', 8.25, 9.5, 72],
            ['D5023', 'LECHE DESLACTOSADA 800 ML', 'ML', 8.7, 10, 36],
            ['SG492', 'YOGUR KREAM 2 LT', 'ML', 25, 28, 60],
            ['SG253', 'GALLETA KREAM CRICK', 'GR', 27, 30, 40],
            ['SG254', 'GALLETA KREAM FIBRA', 'GR', 27, 30, 20],
            ['SG255', 'GALLETA KREAM JIRAFA', 'GR', 27, 30, 20],
            ['SG252', 'GALLETA KREAM PEQUEÑA', 'GR', 5.5, 7, 6],
            ['SG301', 'RIQUITOS', 'GR', 14, 16, 10],
            ['SG509', 'LECHE KREAM NATURAL 800 ML', 'ML', 7.3, 8, 80],
            ['SG225', 'JUGUITO KREAM', 'ML', 0.77, 1, 440],
            ['SG226', 'NARANJITO KREAM', 'ML', 0.42, 0.5, 560],
            ['CAS756', 'COCA QUINA 750 ML', 'ML', 4.85, 7, 12],
            ['CAS650', 'COCA QUINA 3 L', 'ML', 12.75, 14, 6],
            ['CAS003', 'CASCADA SODA', 'ML', 8.95, 10, 18],
            ['CAS093', 'AGUA VILLA SANTA 7 L', 'ML', 13, 15, 10],
            ['CAS095', 'AGUA VILLA SANTA 600 CC', 'ML', 3.2, 5, 36],
            ['CAS092', 'AGUA VILLA SANTA 3 L', 'ML', 6, 9, 12],
            ['CAS604', 'VISCACHANI 600 CC', 'ML', 4.35, 6, 30],
            ['CAS868', 'VISCACHANI 2 L', 'ML', 8.45, 12, 6],
            ['AC060', 'CHICOLIKE BOLSA 1 KG', 'KG', 38.8, 42, 144],
            ['AC010', 'CHICOLIKE BOTE 2 KG', 'KG', 82.8, 85, 12],
            ['AC064', 'CHICOLIKE BOLSA 400 GR', 'GR', 16.6, 19, 96],
            ['AC200', 'CHICOLIKE ALMOHADITAS', 'GR', 10.2, 12, 24],
            ['AC378', 'CORN FLAKES', 'GR', 34, 37, 20],
            ['AC404', 'GALLETA PIT PIZAA', 'GR', 8.4, 10, 24],
            ['AC946', 'SOPA BOLONESA', 'GR', 12.5, 15, 12],
            ['AC864', 'SALSA DE TOMATE 140', 'GR', 3.13, 4.5, 24],
            ['AC800', 'CHOCLITOS LATA', 'GR', 5.75, 7, 24],
            ['SALVI202', 'SALVIETI 1 LT', 'ML', 6.33, 8, 96],
            ['MALT130', 'MALTA', 'ML', 6.25, 8, 120],
            ['SALVI201', 'SALVIETI 300 ML', 'ML', 2.33, 4, 12],
            ['KCH5666', 'KETCHUP 200 GR', 'GR', 8.5, 10, 12],
            ['KCH5667', 'KETCHUP 490 GR', 'GR', 17.5, 19, 8],
            ['KCH5668', 'KETCHUP 47 ML', 'ML', 1.72, 2.5, 50],
            ['MAYO1000', 'MAYONESA 225 ML', 'ML', 10.5, 12, 24],
            ['MAYO1010', 'MAYONESA 485 ML', 'ML', 19, 22, 15],
            ['MAYO1020', 'MAYONESA 47 ML', 'ML', 1.68, 2.5, 100],
            ['MOZT3501', 'MOSTAZA 200 GR', 'GR', 8.5, 10, 6],
            ['MOZT3502', 'MOSTAZA 490 GR', 'GR', 17, 19, 3],
            ['MOZT3503', 'MOSTAZA 47 ML', 'ML', 1.72, 2.5, 50],
            ['NECKR983', 'NECTAR 120 GR', 'GR', 3.8, 4.5, 80],
            ['AVEN2131', 'AVENA INSTANTANEA CAJA 300 GR', 'GR', 11.67, 15, 18],
            ['AVEN2132', 'AVENA INSTANTANEA SACHET 450 GR', 'GR', 15, 18, 10],
            ['AVEN2133', 'AVENA INSANTEANEA CAJA 700 GR', 'GR', 23.33, 26, 12],
            ['AVEN2134', 'CHIAVENA 300 GR', 'GR', 16.5, 19, 20],
            ['CERLPR233', 'CHOCOLEO,DIVERTILOOP,CHOCOPOTAMOS', 'GR', 34, 37, 24],
            ['PEP3000', 'PEPSI 3 L', 'ML', 12.33, 15, 30],
        ];

        foreach ($rows as [$codigo, $nombre, $unidad, $compra, $venta, $stock]) {
            Producto::updateOrCreate(['codigo' => $codigo], [
                'nombre' => $nombre,
                'categoria' => 'SIN CATEGORÍA',
                'unidad' => $unidad,
                'precio_compra' => $compra,
                'precio_venta' => $venta,
                'stock_inicial' => $stock,
            ]);
        }
    }

    public function down(): void
    {
        User::where('username', 'admin')->delete();
    }
};
