<?php

namespace Tests\Feature;

use App\Models\Producto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VencimientosInventarioTest extends TestCase
{
    use RefreshDatabase;

    public function test_por_vencer_sale_del_ultimo_inventario_aplicado_y_quitar_lo_manda_al_final(): void
    {
        $user = User::create([
            'name' => 'REVISOR', 'username' => 'revisor', 'email' => 'revisor@bajocero.test', 'password' => bcrypt('123456'),
        ]);
        $user->givePermissionTo(['Ver Almacenes', 'Crear Almacenes', 'Editar Almacenes', 'Aplicar Almacenes']);
        $nuevo = fn (string $nombre) => Producto::create([
            'codigo' => 'T'.random_int(10000, 99999), 'nombre' => $nombre,
            'unidad' => 'KG', 'precio_compra' => 10, 'precio_venta' => 13, 'stock_inicial' => 0,
        ]);
        $pollo = $nuevo('POLLO');
        $chorizo = $nuevo('CHORIZO');
        $this->actingAs($user);

        // Un inventario viejo con un vencimiento que ya no debe aparecer.
        $viejo = $this->postJson('/api/almacenes', [])->assertCreated()->json('id');
        $this->postJson("/api/almacenes/{$viejo}/detalles", ['producto_id' => $pollo->id, 'cantidad' => 1, 'conteos' => [['lote' => 'VIEJO', 'fecha_vencimiento' => today()->addDays(3)->toDateString(), 'cantidad' => 1]]])->assertCreated();
        $this->postJson("/api/almacenes/{$viejo}/aplicar")->assertOk();

        $id = $this->postJson('/api/almacenes', [])->assertCreated()->json('id');
        $this->postJson("/api/almacenes/{$id}/detalles", ['producto_id' => $pollo->id, 'cantidad' => 3, 'conteos' => [
            ['lote' => 'A', 'fecha_vencimiento' => today()->addDays(5)->toDateString(), 'cantidad' => 2],
            ['lote' => 'B', 'fecha_vencimiento' => today()->subDays(2)->toDateString(), 'cantidad' => 1],
        ]])->assertCreated();
        $this->postJson("/api/almacenes/{$id}/detalles", ['producto_id' => $chorizo->id, 'cantidad' => 4, 'conteos' => [
            ['lote' => 'C', 'fecha_vencimiento' => today()->addDays(10)->toDateString(), 'cantidad' => 4],
        ]])->assertCreated();
        $this->postJson("/api/almacenes/{$id}/aplicar")->assertOk();

        $porVencer = $this->getJson('/api/vencimientos?estado=por_vencer&dias=30')->assertOk();
        $this->assertSame(['A', 'C'], array_column($porVencer->json('data'), 'lote'));
        $this->assertSame($id, $porVencer->json('inventario.id'));
        $this->assertSame(['B'], array_column($this->getJson('/api/vencimientos?estado=vencido')->json('data'), 'lote'));

        $primero = $porVencer->json('data.0');
        $this->putJson("/api/vencimientos/{$primero['tipo']}/{$primero['id']}/ocultar", ['oculto' => true])->assertOk();
        $lista = $this->getJson('/api/vencimientos?estado=por_vencer')->json('data');
        $this->assertSame(['C', 'A'], array_column($lista, 'lote'));
        $this->assertTrue($lista[1]['oculto']);
        $this->assertSame(['C'], array_column($this->getJson('/api/vencimientos?mostrar=activos')->json('data'), 'lote'));
        $this->assertSame(['A'], array_column($this->getJson('/api/vencimientos?q=POLLO')->json('data'), 'lote'));

        $this->putJson("/api/vencimientos/{$primero['tipo']}/{$primero['id']}/ocultar", ['oculto' => false])->assertOk();
        $this->assertSame(['A', 'C'], array_column($this->getJson('/api/vencimientos')->json('data'), 'lote'));

        $this->get('/api/vencimientos-exportar/excel')->assertOk()->assertDownload();
    }
}
