<?php

namespace Tests\Feature;

use App\Models\Almacen;
use App\Models\Producto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventarioAnularTest extends TestCase
{
    use RefreshDatabase;

    private function usuario(string $name, string $username, string $password = '123456'): User
    {
        $user = User::where('username', $username)->first() ?? User::create([
            'name' => $name, 'username' => $username, 'email' => uniqid().'@bajocero.test', 'password' => bcrypt($password),
        ]);
        $user->update(['password' => bcrypt($password)]);
        $user->givePermissionTo(['Ver Almacenes', 'Crear Almacenes', 'Editar Almacenes', 'Aplicar Almacenes', 'Anular Almacenes']);

        return $user;
    }

    public function test_anular_y_eliminar_piden_la_contrasena_del_encargado(): void
    {
        config(['inventario.autoriza_anulacion' => '7308976']);
        $franz = $this->usuario('FRANZ', '7308976', 'clave-franz');
        $cajero = $this->usuario('CAJERO', 'cajero'.uniqid());
        $pan = Producto::create(['codigo' => 'PANX', 'nombre' => 'PAN', 'unidad' => 'UNIDAD', 'precio_compra' => 1, 'precio_venta' => 2, 'stock_inicial' => 5]);

        $id = $this->actingAs($cajero)->postJson('/api/almacenes', [])->json('id');
        $this->postJson("/api/almacenes/{$id}/detalles", ['producto_id' => $pan->id, 'cantidad' => 9])->assertCreated();
        $this->postJson("/api/almacenes/{$id}/aplicar")->assertOk();
        $this->assertEqualsWithDelta(9, (float) $pan->fresh()->stock_inicial, 0.0001);

        $this->putJson("/api/almacenes/{$id}/anular")->assertStatus(422)->assertJsonValidationErrors('password');
        // La contraseña de quien anula no sirve: tiene que ser la del encargado.
        $this->putJson("/api/almacenes/{$id}/anular", ['password' => '123456'])->assertStatus(422);
        $this->assertSame('APLICADO', Almacen::find($id)->estado);

        $this->putJson("/api/almacenes/{$id}/anular", ['password' => 'clave-franz'])->assertOk();
        $this->assertSame('ANULADO', Almacen::find($id)->estado);
        $this->assertStringContainsString('AUTORIZÓ FRANZ', Almacen::find($id)->observacion);
        $this->assertEqualsWithDelta(5, (float) $pan->fresh()->stock_inicial, 0.0001);

        $borrador = $this->postJson('/api/almacenes', [])->json('id');
        $this->deleteJson("/api/almacenes/{$borrador}", ['password' => 'mala'])->assertStatus(422);
        $this->assertNotNull(Almacen::find($borrador));
        $this->deleteJson("/api/almacenes/{$borrador}", ['password' => 'clave-franz'])->assertOk();
        $this->assertNull(Almacen::find($borrador));
        $this->assertNotNull($franz->fresh());
    }

    public function test_tras_cinco_intentos_fallidos_se_bloquea(): void
    {
        config(['inventario.autoriza_anulacion' => '7308976']);
        $this->usuario('FRANZ', '7308976', 'clave-franz');
        $cajero = $this->usuario('CAJERO', 'cajero'.uniqid());
        $id = $this->actingAs($cajero)->postJson('/api/almacenes', [])->json('id');

        foreach (range(1, 5) as $i) {
            $this->putJson("/api/almacenes/{$id}/anular", ['password' => 'mala'])->assertStatus(422);
        }
        $this->putJson("/api/almacenes/{$id}/anular", ['password' => 'clave-franz'])->assertStatus(429);
    }
}
