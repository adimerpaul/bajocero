<?php

namespace Tests\Feature;

use App\Models\Lote;
use App\Models\Producto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventarioPorUsuarioTest extends TestCase
{
    use RefreshDatabase;

    private function usuario(string $name): User
    {
        $user = User::create([
            'name' => $name, 'username' => strtolower($name).uniqid(),
            'email' => uniqid().'@bajocero.test', 'password' => bcrypt('123456'),
        ]);
        $user->givePermissionTo(['Ver Almacenes', 'Crear Almacenes', 'Editar Almacenes', 'Aplicar Almacenes']);

        return $user;
    }

    public function test_cada_usuario_cuenta_aparte_y_el_producto_suma_todo(): void
    {
        [$adimer, $jose, $franz] = [$this->usuario('ADIMER'), $this->usuario('JOSE'), $this->usuario('FRANZ')];
        $pan = Producto::create(['codigo' => 'PAN1', 'nombre' => 'PAN MOLDE', 'unidad' => 'UNIDAD', 'precio_compra' => 10, 'precio_venta' => 15, 'stock_inicial' => 8]);
        $id = $this->actingAs($adimer)->postJson('/api/almacenes', [])->assertCreated()->json('id');

        $this->actingAs($adimer)->postJson("/api/almacenes/{$id}/detalles", ['producto_id' => $pan->id, 'cantidad' => 3])->assertCreated();
        $this->actingAs($jose)->postJson("/api/almacenes/{$id}/detalles", ['producto_id' => $pan->id, 'cantidad' => 4])->assertCreated();
        $detail = $this->actingAs($franz)->postJson("/api/almacenes/{$id}/detalles", ['producto_id' => $pan->id, 'cantidad' => 1])->assertCreated();

        $this->assertEqualsWithDelta(8, (float) $detail->json('cantidad'), 0.0001);
        $this->assertSame(['ADIMER', 'FRANZ', 'JOSE'], array_column($detail->json('usuarios'), 'usuario_nombre'));

        // Volver a contar reemplaza sólo el conteo propio.
        $this->actingAs($jose)->postJson("/api/almacenes/{$id}/detalles", ['producto_id' => $pan->id, 'cantidad' => 5])->assertCreated()
            ->assertJsonCount(3, 'usuarios')->assertJsonPath('cantidad', '9.000');

        $progress = $this->getJson("/api/almacenes/{$id}/avance")->assertOk()->assertJson(['revisados' => 1, 'con_diferencia' => 1]);
        $this->assertCount(3, $progress->json('por_usuario'));

        $export = $this->getJson("/api/almacenes/{$id}/exportar-datos")->assertOk()->assertJson(['total' => 1]);
        $this->assertSame('PAN MOLDE', $export->json('data.0.1'));
        $this->assertEqualsWithDelta(9, $export->json('data.0.4'), 0.0001);
        $this->assertCount(3, $export->json('data.0.3'));

        // Quitar el conteo de un usuario resta su parte.
        $franzRow = collect($detail->json('usuarios'))->firstWhere('usuario_nombre', 'FRANZ');
        $this->deleteJson("/api/almacenes/{$id}/detalles/{$detail->json('id')}/usuarios/{$franzRow['id']}")->assertOk();
        $this->assertEqualsWithDelta(8, (float) $this->getJson("/api/almacenes/{$id}/detalles")->json('data.0.cantidad'), 0.0001);

        $this->postJson("/api/almacenes/{$id}/aplicar")->assertOk();
        $this->assertEqualsWithDelta(8, (float) $pan->fresh()->stock_inicial, 0.0001);
    }

    public function test_lotes_de_uno_y_cantidad_suelta_de_otro_quedan_en_los_lotes(): void
    {
        [$adimer, $jose] = [$this->usuario('ADIMER'), $this->usuario('JOSE')];
        $pollo = Producto::create(['codigo' => 'POL1', 'nombre' => 'POLLO', 'unidad' => 'KG', 'precio_compra' => 20, 'precio_venta' => 25, 'stock_inicial' => 0]);
        $id = $this->actingAs($adimer)->postJson('/api/almacenes', [])->assertCreated()->json('id');

        $this->actingAs($adimer)->postJson("/api/almacenes/{$id}/detalles", ['producto_id' => $pollo->id, 'conteos' => [
            ['lote' => 'A', 'fecha_vencimiento' => today()->addDays(5)->toDateString(), 'cantidad' => 2.5],
        ]])->assertCreated();
        $this->actingAs($jose)->postJson("/api/almacenes/{$id}/detalles", ['producto_id' => $pollo->id, 'cantidad' => 1.25])->assertCreated();

        $this->actingAs($adimer)->postJson("/api/almacenes/{$id}/aplicar")->assertOk();
        $this->assertEqualsWithDelta(3.75, (float) $pollo->fresh()->stock_inicial, 0.0001);
        $this->assertEqualsWithDelta(3.75, (float) Lote::where('producto_id', $pollo->id)->sum('cantidad_disponible'), 0.0001);
    }
}
