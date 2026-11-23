<?php

namespace Tests\Feature;

use App\Models\Producto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductosRiesgoTest extends TestCase
{
    use RefreshDatabase;

    private function usuario(string $name): User
    {
        $user = User::create(['name' => $name, 'username' => strtolower($name), 'email' => strtolower($name).'@bajocero.test', 'password' => bcrypt('123456')]);
        $user->givePermissionTo(['Ver Productos en Riesgo', 'Gestionar Productos en Riesgo']);

        return $user;
    }

    public function test_agregar_y_quitar_guarda_historial_sin_tocar_stock(): void
    {
        $ana = $this->usuario('ANA');
        $luis = $this->usuario('LUIS');
        $pollo = Producto::create(['codigo' => 'P1', 'nombre' => 'POLLO', 'unidad' => 'KG', 'precio_compra' => 10, 'precio_venta' => 13, 'stock_inicial' => 7.5]);
        $payload = ['producto_id' => $pollo->id, 'lote' => 'l1', 'fecha_vencimiento' => today()->addDays(4)->toDateString(), 'cantidad' => 2.25, 'origen' => 'POR_VENCER', 'observacion' => 'olor raro'];

        $id = $this->actingAs($ana)->postJson('/api/productos-riesgo', $payload)->assertCreated()
            ->assertJson(['estado' => 'ACTIVO', 'lote' => 'L1', 'agregado_por_nombre' => 'ANA', 'observacion' => 'OLOR RARO'])->json('id');
        $this->postJson('/api/productos-riesgo', $payload)->assertStatus(409);

        $this->actingAs($luis)->putJson("/api/productos-riesgo/{$id}/quitar", ['motivo' => 'se vendió'])->assertOk()
            ->assertJson(['estado' => 'QUITADO', 'quitado_por_nombre' => 'LUIS', 'motivo_quitado' => 'SE VENDIÓ']);
        $this->putJson("/api/productos-riesgo/{$id}/quitar")->assertStatus(422);

        // Se puede volver a agregar: es una fila nueva y la anterior queda en el historial.
        $this->postJson('/api/productos-riesgo', $payload)->assertCreated();
        $this->getJson('/api/productos-riesgo?estado=ACTIVO')->assertOk()->assertJson(['totales' => ['ACTIVO' => 1, 'QUITADO' => 1]])->assertJsonCount(1, 'data');
        $this->getJson('/api/productos-riesgo?estado=QUITADO')->assertJsonPath('data.0.agregado_por_nombre', 'ANA')->assertJsonPath('data.0.quitado_por_nombre', 'LUIS');
        $this->assertCount(2, $this->getJson('/api/productos-riesgo?estado=TODOS')->json('data'));

        $this->assertEqualsWithDelta(7.5, (float) $pollo->fresh()->stock_inicial, 0.0001);
    }

    public function test_aplicar_inventario_manda_a_riesgo_todo_lo_que_tiene_vencimiento(): void
    {
        $user = $this->usuario('CONTADOR');
        $user->givePermissionTo(['Ver Almacenes', 'Crear Almacenes', 'Editar Almacenes', 'Aplicar Almacenes']);
        $nuevo = fn (string $nombre) => Producto::create(['codigo' => 'T'.random_int(10000, 99999), 'nombre' => $nombre, 'unidad' => 'KG', 'precio_compra' => 10, 'precio_venta' => 13, 'stock_inicial' => 0]);
        $pollo = $nuevo('POLLO');
        $chorizo = $nuevo('CHORIZO');
        $queso = $nuevo('QUESO');
        $this->actingAs($user);

        // Un riesgo cargado a mano antes del inventario: al aplicar se quita (queda en el historial).
        $this->postJson('/api/productos-riesgo', ['producto_id' => $pollo->id, 'lote' => 'A', 'fecha_vencimiento' => today()->addDays(40)->toDateString()])->assertCreated();

        $id = $this->postJson('/api/almacenes', [])->assertCreated()->json('id');
        $this->postJson("/api/almacenes/{$id}/detalles", ['producto_id' => $pollo->id, 'cantidad' => 3, 'conteos' => [
            ['lote' => 'a', 'fecha_vencimiento' => today()->addDays(40)->toDateString(), 'cantidad' => 2],
            ['lote' => 'B', 'fecha_vencimiento' => today()->addDays(200)->toDateString(), 'cantidad' => 1],
        ]])->assertCreated();
        $this->postJson("/api/almacenes/{$id}/detalles", ['producto_id' => $chorizo->id, 'cantidad' => 4, 'conteos' => [['lote' => 'C', 'cantidad' => 4]]])->assertCreated();
        $this->postJson("/api/almacenes/{$id}/detalles", ['producto_id' => $queso->id, 'cantidad' => 1, 'fecha_vencimiento' => today()->subDay()->toDateString()])->assertCreated();

        $this->postJson("/api/almacenes/{$id}/aplicar")->assertOk()->assertJson(['riesgo_agregados' => 3]);

        $rows = $this->getJson('/api/productos-riesgo?estado=ACTIVO')->assertOk()->json('data');
        $this->assertCount(3, $rows);
        $this->assertSame(['INVENTARIO'], collect($rows)->pluck('origen')->unique()->values()->all());
        $quitado = $this->getJson('/api/productos-riesgo?estado=QUITADO')->json('data.0');
        $this->assertSame(['MANUAL', 'CONTADOR'], [$quitado['origen'], $quitado['quitado_por_nombre']]);
        $this->assertStringStartsWith('REEMPLAZADO POR EL INVENTARIO', $quitado['motivo_quitado']);
        $fromInventory = collect($rows)->where('origen', 'INVENTARIO');
        $this->assertEqualsCanonicalizing(['A', 'B', null], $fromInventory->pluck('lote')->all());
        $this->assertSame(['CONTADOR'], $fromInventory->pluck('agregado_por_nombre')->unique()->values()->all());
        $this->assertSame($id, $fromInventory->first()['almacen_id']);
    }

    public function test_sin_permiso_no_puede_agregar(): void
    {
        $user = User::create(['name' => 'X', 'username' => 'x', 'email' => 'x@bajocero.test', 'password' => bcrypt('123456')]);
        $user->givePermissionTo('Ver Productos en Riesgo');
        $p = Producto::create(['codigo' => 'P2', 'nombre' => 'CHORIZO', 'unidad' => 'KG', 'precio_compra' => 1, 'precio_venta' => 2, 'stock_inicial' => 0]);

        $this->actingAs($user)->getJson('/api/productos-riesgo')->assertOk();
        $this->postJson('/api/productos-riesgo', ['producto_id' => $p->id])->assertForbidden();
    }
}
