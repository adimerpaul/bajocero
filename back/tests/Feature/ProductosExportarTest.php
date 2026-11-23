<?php

namespace Tests\Feature;

use App\Models\Producto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductosExportarTest extends TestCase
{
    use RefreshDatabase;

    private function usuario(array $permisos = ['Ver Productos']): User
    {
        $user = User::create([
            'name' => 'REPORTES', 'username' => 'reportes'.uniqid(),
            'email' => uniqid().'@bajocero.test', 'password' => bcrypt('123456'),
        ]);
        $user->givePermissionTo($permisos);

        return $user;
    }

    public function test_los_datos_del_excel_se_entregan_por_bloques_respetando_filtros_y_orden(): void
    {
        Producto::query()->forceDelete();
        foreach (range(1, 250) as $i) {
            Producto::create([
                'codigo' => 'E'.str_pad((string) $i, 4, '0', STR_PAD_LEFT), 'nombre' => 'PRODUCTO '.str_pad((string) $i, 3, '0', STR_PAD_LEFT),
                'unidad' => $i % 2 ? 'KG' : 'UNIDAD', 'precio_compra' => 10, 'precio_venta' => 13, 'stock_inicial' => $i % 5 ? 1.5 : 0,
            ]);
        }
        $this->actingAs($this->usuario());

        $first = $this->getJson('/api/productos-exportar/datos?per_page=100&sort_by=nombre')->assertOk();
        $first->assertJson(['page' => 1, 'per_page' => 100, 'total' => 250]);
        $this->assertCount(100, $first->json('data'));
        $this->assertSame(['E0001', null, 'PRODUCTO 001'], array_slice($first->json('data.0'), 0, 3));
        $this->assertEqualsWithDelta(1.5, $first->json('data.0.12'), 0.0001);

        $last = $this->getJson('/api/productos-exportar/datos?per_page=100&page=3&sort_by=nombre')->assertOk();
        $this->assertNull($last->json('total'));
        $this->assertCount(50, $last->json('data'));
        $this->assertSame('PRODUCTO 250', $last->json('data.49.2'));

        $this->getJson('/api/productos-exportar/datos?per_page=100&unidad=kg&stock=sin')->assertOk()->assertJson(['total' => 25]);
    }

    public function test_sin_permiso_no_se_exporta(): void
    {
        $this->actingAs($this->usuario(['Ver Ventas']));
        $this->getJson('/api/productos-exportar/datos')->assertForbidden();
    }
}
