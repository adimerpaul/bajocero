<?php

namespace Tests\Feature;

use App\Models\Producto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventarioOrdenTest extends TestCase
{
    use RefreshDatabase;

    public function test_el_detalle_se_ordena_por_columna_de_menor_a_mayor_y_de_mayor_a_menor(): void
    {
        $user = User::create(['name' => 'ANA', 'username' => 'ana', 'email' => 'ana@bajocero.test', 'password' => bcrypt('123456')]);
        $user->givePermissionTo(['Ver Almacenes', 'Crear Almacenes', 'Editar Almacenes']);
        $nuevo = fn (string $nombre, float $stock) => Producto::create(['codigo' => 'T'.random_int(10000, 99999), 'nombre' => $nombre, 'unidad' => 'KG', 'precio_compra' => 10, 'precio_venta' => 13, 'stock_inicial' => $stock]);
        $this->actingAs($user);
        $id = $this->postJson('/api/almacenes', [])->assertCreated()->json('id');

        $this->postJson("/api/almacenes/{$id}/detalles", ['producto_id' => $nuevo('BETA', 1)->id, 'cantidad' => 5])->assertCreated();
        $this->postJson("/api/almacenes/{$id}/detalles", ['producto_id' => $nuevo('ALFA', 10)->id, 'cantidad' => 2, 'conteos' => [['lote' => 'L', 'fecha_vencimiento' => today()->addDays(9)->toDateString(), 'cantidad' => 2]]])->assertCreated();
        $this->postJson("/api/almacenes/{$id}/detalles", ['producto_id' => $nuevo('GAMA', 3)->id, 'cantidad' => 9, 'conteos' => [['lote' => 'M', 'fecha_vencimiento' => today()->addDays(2)->toDateString(), 'cantidad' => 9]]])->assertCreated();

        $names = fn (string $query) => array_column($this->getJson("/api/almacenes/{$id}/detalles?{$query}")->assertOk()->json('data'), 'nombre');

        $this->assertSame(['ALFA', 'BETA', 'GAMA'], $names('ordenar=producto&direccion=asc'));
        $this->assertSame(['GAMA', 'BETA', 'ALFA'], $names('ordenar=producto&direccion=desc'));
        $this->assertSame(['GAMA', 'BETA', 'ALFA'], $names('ordenar=contado&direccion=desc'));
        $this->assertSame(['ALFA', 'GAMA', 'BETA'], $names('ordenar=sistema&direccion=desc'));
        $this->assertSame(['ALFA', 'BETA', 'GAMA'], $names('ordenar=diferencia&direccion=asc'));   // -8, +4, +6
        $this->assertSame(['GAMA', 'ALFA', 'BETA'], $names('ordenar=lote&direccion=asc'));        // sin fecha al final
        $this->assertSame(['ALFA', 'GAMA', 'BETA'], $names('ordenar=lote&direccion=desc'));
        $this->assertSame(['GAMA', 'BETA', 'ALFA'], $names("ordenar=user_u{$user->id}&direccion=desc"));
        $this->assertSame(['ALFA', 'BETA'], $names('ordenar=contado&direccion=asc&per_page=2'));
    }
}
