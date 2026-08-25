<?php

namespace Tests\Feature;

use App\Models\Producto;
use App\Models\User;
use App\Models\Venta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class VentaOfflineTest extends TestCase
{
    use RefreshDatabase;

    private function cajero(string ...$permisos): User
    {
        $user = User::create([
            'name' => 'CAJERO', 'username' => 'cajero'.uniqid(),
            'email' => uniqid().'@bajocero.test', 'password' => bcrypt('123456'),
        ]);
        $user->givePermissionTo($permisos ?: ['Crear Ventas', 'Crear Ventas Offline']);

        return $user;
    }

    private function producto(float $stock = 10): Producto
    {
        return Producto::create([
            'codigo' => 'T'.random_int(10000, 99999), 'nombre' => 'PRODUCTO PRUEBA',
            'unidad' => 'UND', 'precio_compra' => 5, 'precio_venta' => 10, 'stock_inicial' => $stock,
        ]);
    }

    private function cuerpo(Producto $producto, string $uuid, float $cantidad = 2): array
    {
        return [
            'uuid' => $uuid,
            'fecha_offline' => now()->subHour()->toIso8601String(),
            'tipo_pago' => 'EFECTIVO',
            'detalles' => [['producto_id' => $producto->id, 'cantidad' => $cantidad, 'precio_venta' => 10]],
        ];
    }

    public function test_una_venta_offline_reenviada_no_se_duplica(): void
    {
        $user = $this->cajero();
        $producto = $this->producto(10);
        $uuid = (string) Str::uuid();

        $primera = $this->actingAs($user)->postJson('/api/ventas', $this->cuerpo($producto, $uuid));
        $primera->assertCreated();

        // Mismo uuid otra vez: el servidor devuelve la venta ya registrada.
        $segunda = $this->actingAs($user)->postJson('/api/ventas', $this->cuerpo($producto, $uuid));
        $segunda->assertOk()->assertJson(['duplicada' => true, 'id' => $primera->json('id')]);

        $this->assertSame(1, Venta::where('uuid', $uuid)->count());
        // El stock se descontó una sola vez.
        $this->assertEqualsWithDelta(8, (float) $producto->fresh()->stock_inicial, 0.001);
    }

    public function test_la_venta_offline_conserva_la_hora_del_cobro(): void
    {
        $user = $this->cajero();
        $producto = $this->producto();
        $cuerpo = $this->cuerpo($producto, (string) Str::uuid());

        $this->actingAs($user)->postJson('/api/ventas', $cuerpo)->assertCreated();

        $venta = Venta::latest('id')->first();
        $this->assertNotNull($venta->fecha_offline);
        $this->assertEqualsWithDelta(now()->subHour()->timestamp, $venta->fecha->timestamp, 5);
    }

    public function test_guarda_el_precio_base_y_detecta_un_precio_modificado(): void
    {
        $user = $this->cajero();
        $producto = $this->producto();
        $cuerpo = $this->cuerpo($producto, (string) Str::uuid());
        $cuerpo['detalles'][0]['precio_venta'] = 8.50;

        $respuesta = $this->actingAs($user)->postJson('/api/ventas', $cuerpo);

        $respuesta->assertCreated()
            ->assertJsonPath('detalles.0.precio_base', '10.0000')
            ->assertJsonPath('detalles.0.precio_venta', '8.5000')
            ->assertJsonPath('detalles.0.precio_cambiado', true);
        $this->assertDatabaseHas('venta_detalles', [
            'producto_id' => $producto->id,
            'precio_base' => 10,
            'precio_venta' => 8.5,
            'precio_cambiado' => true,
        ]);
    }

    public function test_exporta_los_precios_modificados_en_excel_y_pdf(): void
    {
        $user = $this->cajero('Crear Ventas', 'Ver Ventas');
        $producto = $this->producto();
        $cuerpo = $this->cuerpo($producto, (string) Str::uuid());
        $cuerpo['detalles'][0]['precio_venta'] = 8.50;
        $this->actingAs($user)->postJson('/api/ventas', $cuerpo)->assertCreated();

        $this->actingAs($user)->get('/api/ventas-exportar/precios-modificados/excel')
            ->assertOk()->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->actingAs($user)->get('/api/ventas-exportar/precios-modificados/pdf')
            ->assertOk()->assertHeader('content-type', 'application/pdf');
    }

    public function test_la_verificacion_informa_los_uuid_ya_registrados(): void
    {
        $user = $this->cajero();
        $producto = $this->producto();
        $enviado = (string) Str::uuid();
        $pendiente = (string) Str::uuid();
        $this->actingAs($user)->postJson('/api/ventas', $this->cuerpo($producto, $enviado))->assertCreated();

        $respuesta = $this->actingAs($user)->postJson('/api/ventas-offline/verificar', ['uuids' => [$enviado, $pendiente]]);

        $respuesta->assertOk();
        $this->assertArrayHasKey($enviado, $respuesta->json('registradas'));
        $this->assertArrayNotHasKey($pendiente, $respuesta->json('registradas'));
    }

    public function test_el_permiso_offline_alcanza_para_enviar_la_cola_pero_no_para_vender_en_linea(): void
    {
        $user = $this->cajero('Crear Ventas Offline');
        $producto = $this->producto();
        $cuerpo = $this->cuerpo($producto, (string) Str::uuid());

        $this->actingAs($user)->postJson('/api/ventas', $cuerpo)->assertCreated();

        unset($cuerpo['uuid'], $cuerpo['fecha_offline']);
        $this->actingAs($user)->postJson('/api/ventas', $cuerpo)->assertForbidden();
    }
}
