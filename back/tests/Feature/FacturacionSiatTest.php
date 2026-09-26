<?php

namespace Tests\Feature;

use App\Mail\FacturaMail;
use App\Models\Categoria;
use App\Models\Cliente;
use App\Models\Producto;
use App\Models\SiatCufd;
use App\Models\User;
use App\Models\Venta;
use App\Services\Siat\CufGenerator;
use App\Services\Siat\FacturaCorreoService;
use App\Services\Siat\SiatClient;
use App\Services\Siat\SiatSinConexionException;
use DOMDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** SIAT con un cliente SOAP falso: nada sale a internet. */
class FacturacionSiatTest extends TestCase
{
    use RefreshDatabase;

    private FakeSiatClient $siat;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['siat.enabled' => true, 'siat.nit' => '7308976010', 'siat.codigo_sistema' => 'SISTEMA']);
        $this->siat = new FakeSiatClient;
        $this->app->instance(SiatClient::class, $this->siat);
    }

    private function cajero(): User
    {
        $user = User::create([
            'name' => 'CAJERO', 'username' => 'cajero'.uniqid(),
            'email' => uniqid().'@bajocero.test', 'password' => bcrypt('123456'),
        ]);
        $user->givePermissionTo(['Crear Ventas', 'Ver Ventas', 'Anular Ventas', 'Gestionar Impuestos']);

        return $user;
    }

    private function producto(string $unidad = 'KG', ?Categoria $categoria = null): Producto
    {
        return Producto::create([
            'codigo' => 'T'.random_int(10000, 99999), 'nombre' => 'PECHUGA DE POLLO', 'categoria_id' => $categoria?->id,
            'unidad' => $unidad, 'precio_compra' => 20, 'precio_venta' => 38.5, 'stock_inicial' => 50,
        ]);
    }

    private function venta(User $user, array $extra = []): TestResponse
    {
        $pollo = $this->producto('KG', Categoria::create(['nombre' => 'POLLO X', 'actividad_economica' => '4711100', 'codigo_producto_sin' => 1004672]));
        $pieza = $this->producto('PZS');

        return $this->actingAs($user)->postJson('/api/ventas', $extra + [
            'tipo_pago' => 'EFECTIVO', 'descuento' => 1.37,
            'detalles' => [
                ['producto_id' => $pollo->id, 'cantidad' => 1.355, 'precio_venta' => 38.5],
                ['producto_id' => $pieza->id, 'cantidad' => 2, 'precio_venta' => 7.3333],
            ],
        ]);
    }

    public function test_el_cuf_coincide_con_el_algoritmo_del_sin(): void
    {
        $cuf = (new CufGenerator)->generate('7308976010', '20261119154408722', 0, 2, 2, 91807, 2, 'ABC');

        $this->assertSame('1F419B5F2CC32A79C50C82D4D87E6B21F8BDA1EDD78ABC', $cuf);
    }

    public function test_factura_en_linea_validada_con_xml_computarizado_valido(): void
    {
        $response = $this->venta($this->cajero(), [
            'tipo_documento' => 'CI', 'numero_documento' => '5115889', 'complemento' => '1a', 'cliente_nombre' => 'juan perez',
        ]);

        $response->assertCreated()->assertJson(['tipo_comprobante' => 'FACTURA', 'numero_factura' => 1, 'estado_siat' => 'VALIDADA', 'tipo_emision' => 1]);
        $this->assertStringContainsString('cuf=', $response->json('factura_url'));
        $this->assertDatabaseHas('clientes', ['numero_documento' => '5115889', 'complemento' => '1A', 'nombre' => 'JUAN PEREZ']);

        $xml = Storage::disk('local')->get(Venta::first()->xml_path);
        $doc = new DOMDocument;
        $doc->loadXML($xml);
        $this->assertTrue($doc->schemaValidate(resource_path('siat/facturaComputarizadaCompraVenta.xsd')));
        $this->assertSame('facturaComputarizadaCompraVenta', $doc->documentElement->nodeName);
        $this->assertStringNotContainsString('Signature', $xml);
        $this->assertStringContainsString('<codigoProductoSin>1004672</codigoProductoSin>', $xml);
        $this->assertStringContainsString('<unidadMedida>22</unidadMedida>', $xml);

        // Las igualdades que valida el SIN, pese a los kilos con 3 decimales.
        $sx = simplexml_load_string($xml);
        $sum = 0;
        foreach ($sx->detalle as $line) {
            $this->assertEqualsWithDelta((float) $line->subTotal, round((float) $line->cantidad * (float) $line->precioUnitario - (float) $line->montoDescuento, 2), 0.001);
            $sum += (float) $line->subTotal;
        }
        $this->assertEqualsWithDelta((float) $sx->cabecera->montoTotal, $sum - (float) $sx->cabecera->descuentoAdicional, 0.001);
        $this->assertEqualsWithDelta((float) $response->json('total'), (float) $sx->cabecera->montoTotal, 0.001);
    }

    public function test_sin_documento_se_factura_a_control_tributario(): void
    {
        $this->venta($this->cajero())->assertCreated()->assertJson(['numero_documento' => '99002', 'cliente_nombre' => 'CONTROL TRIBUTARIO']);

        $xml = Storage::disk('local')->get(Venta::first()->xml_path);
        $this->assertStringContainsString('<numeroDocumento>99002</numeroDocumento>', $xml);
        $this->assertStringContainsString('<nombreRazonSocial>CONTROL TRIBUTARIO</nombreRazonSocial>', $xml);
        $this->assertSame(0, Cliente::count());
    }

    public function test_el_numero_de_factura_es_correlativo_y_los_recibos_no_lo_consumen(): void
    {
        $user = $this->cajero();
        $this->venta($user)->assertJson(['numero_factura' => 1]);
        $this->venta($user, ['tipo_comprobante' => 'RECIBO'])->assertJson(['tipo_comprobante' => 'RECIBO', 'numero_factura' => null, 'estado_siat' => null]);
        $this->venta($user)->assertJson(['numero_factura' => 2]);

        $this->assertSame(2, collect($this->siat->calls)->where('method', 'recepcionFactura')->count());
    }

    public function test_sin_conexion_queda_fuera_de_linea_y_sale_en_un_evento_significativo(): void
    {
        $user = $this->cajero();
        $this->siat->offline = ['recepcionFactura'];
        $this->venta($user)->assertCreated()->assertJson(['estado_siat' => 'PENDIENTE_EVENTO', 'tipo_emision' => 2]);
        $this->venta($user)->assertJson(['estado_siat' => 'PENDIENTE_EVENTO']);

        // No se puede anular algo que el SIN todavía no conoce.
        $this->actingAs($user)->putJson('/api/ventas/'.Venta::first()->id.'/anular', ['codigo_motivo' => 1])->assertStatus(422);

        $this->siat->offline = [];
        $this->travel(5)->minutes();
        $this->actingAs($user)->postJson('/api/siat/eventos', ['codigo_motivo' => 2])->assertOk()
            ->assertJsonPath('eventos.0.estado', 'VALIDADO')->assertJsonPath('eventos.0.cantidad_facturas', 2);

        $this->assertSame(2, Venta::where('estado_siat', 'VALIDADA')->count());
        $package = collect($this->siat->calls)->firstWhere('method', 'recepcionPaqueteFactura')['payload']['SolicitudServicioRecepcionPaquete'];
        $this->assertSame(2, $package['codigoEmision']);
        $this->assertSame(2, $package['cantidadFacturas']);
        $this->assertSame('EVT-1', $package['codigoEvento']);
    }

    public function test_sin_cufd_ni_conexion_la_venta_se_guarda_y_la_factura_queda_pendiente(): void
    {
        $this->siat->offline = ['cuis', 'cufd', 'recepcionFactura'];

        $this->venta($this->cajero())->assertCreated()->assertJson(['estado_siat' => 'PENDIENTE', 'cuf' => null]);
    }

    public function test_rechazo_del_sin_queda_observada_y_se_reemite_con_datos_corregidos(): void
    {
        $user = $this->cajero();
        $this->siat->rechazar = true;
        $venta = $this->venta($user, ['tipo_documento' => 'NIT', 'numero_documento' => '123', 'cliente_nombre' => 'X'])
            ->assertJson(['estado_siat' => 'OBSERVADA'])->json();

        $this->siat->rechazar = false;
        $this->actingAs($user)->putJson("/api/ventas/{$venta['id']}/reemitir-factura", [
            'tipo_documento' => 'CI', 'numero_documento' => '5115889', 'cliente_nombre' => 'Juan Perez',
        ])->assertOk()->assertJson(['estado_siat' => 'VALIDADA', 'numero_factura' => $venta['numero_factura'], 'numero_documento' => '5115889']);
    }

    public function test_anular_una_factura_validada_la_anula_en_impuestos_y_devuelve_el_stock(): void
    {
        $user = $this->cajero();
        $venta = $this->venta($user)->json();

        $this->actingAs($user)->putJson("/api/ventas/{$venta['id']}/anular")->assertStatus(422); // falta el motivo
        $this->actingAs($user)->putJson("/api/ventas/{$venta['id']}/anular", ['codigo_motivo' => 1])
            ->assertOk()->assertJson(['estado' => 'ANULADA', 'estado_siat' => 'ANULADA']);

        $this->assertSame(1, collect($this->siat->calls)->where('method', 'anulacionFactura')->count());
        $this->assertEqualsWithDelta(50, (float) Producto::find($venta['detalles'][0]['producto_id'])->stock_inicial, 0.001);
    }

    public function test_una_venta_offline_de_la_caja_se_factura_con_la_hora_del_cobro(): void
    {
        $user = $this->cajero();
        $user->givePermissionTo('Crear Ventas Offline');
        SiatCufd::create(['codigo' => 'CUFD-AYER', 'codigo_control' => 'CTRL0', 'vence_en' => now()->addHours(10)])
            ->forceFill(['created_at' => now()->subHours(14)])->save();
        $cobro = now()->subHours(2)->startOfSecond();

        $this->venta($user, ['uuid' => (string) Str::uuid(), 'fecha_offline' => $cobro->toIso8601String()])
            ->assertCreated()->assertJson(['estado_siat' => 'PENDIENTE_EVENTO', 'tipo_emision' => 2, 'cufd' => 'CUFD-AYER']);

        $this->assertTrue(Venta::first()->fecha_emision_siat->equalTo($cobro));
        $this->assertSame(0, collect($this->siat->calls)->where('method', 'recepcionFactura')->count());
    }

    public function test_la_factura_se_envia_por_correo_con_pdf_y_xml_sin_guardar_el_pdf(): void
    {
        Mail::fake();
        $user = $this->cajero();
        $venta = $this->venta($user, [
            'tipo_documento' => 'CI', 'numero_documento' => '5115889', 'cliente_nombre' => 'Juan', 'cliente_email' => 'juan@correo.test',
        ])->assertJson(['estado_siat' => 'VALIDADA'])->json();

        Mail::assertSent(FacturaMail::class, function (FacturaMail $mail) {
            $names = collect($mail->attachments())->map(fn ($a) => $a->as)->all();

            return $mail->hasTo('juan@correo.test') && ! $mail->anulada && $names === ['Factura_1.pdf', 'Factura_1.xml'];
        });
        $this->assertNotNull(Venta::find($venta['id'])->email_enviado_en);
        // Sólo el XML queda en disco; el PDF se generó en memoria.
        $this->assertSame([], array_values(array_filter(Storage::disk('local')->allFiles(), fn ($f) => str_ends_with($f, '.pdf'))));

        $pdf = $this->actingAs($user)->get("/api/ventas/{$venta['id']}/factura-pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $pdf->getContent());

        $this->actingAs($user)->putJson("/api/ventas/{$venta['id']}/anular", ['codigo_motivo' => 1])->assertOk();
        Mail::assertSent(FacturaMail::class, fn (FacturaMail $mail) => $mail->anulada && $mail->motivo === 'FACTURA MAL EMITIDA');
    }

    public function test_sin_correo_no_se_envia_y_se_puede_mandar_despues(): void
    {
        Mail::fake();
        $user = $this->cajero();
        $venta = $this->venta($user, ['tipo_documento' => 'CI', 'numero_documento' => '5115889', 'cliente_nombre' => 'Juan'])->json();
        Mail::assertNothingSent();

        $this->actingAs($user)->postJson("/api/ventas/{$venta['id']}/enviar-factura")->assertStatus(422);
        $this->actingAs($user)->postJson("/api/ventas/{$venta['id']}/enviar-factura", ['email' => 'otro@correo.test'])->assertOk();
        Mail::assertSent(FacturaMail::class, fn (FacturaMail $mail) => $mail->hasTo('otro@correo.test'));
        $this->assertDatabaseHas('clientes', ['numero_documento' => '5115889', 'email' => 'otro@correo.test']);
    }

    public function test_enviar_todo_lo_pendiente_de_una_vez(): void
    {
        $user = $this->cajero();
        $this->siat->offline = ['recepcionFactura'];
        $this->venta($user)->assertJson(['estado_siat' => 'PENDIENTE_EVENTO']);
        $this->venta($user)->assertJson(['estado_siat' => 'PENDIENTE_EVENTO']);
        config(['siat.enabled' => false]);
        $this->venta($user)->assertJson(['estado_siat' => 'PENDIENTE']);

        $this->actingAs($user)->getJson('/api/ventas-resumen?desde=2000-01-01&hasta=2000-01-01')->assertJson(['por_enviar' => 3]);
        $this->assertCount(3, $this->actingAs($user)->getJson('/api/ventas?por_enviar=1&desde=2000-01-01')->json('data'));

        config(['siat.enabled' => true]);
        $this->siat->offline = [];
        $this->travel(5)->minutes();
        $this->actingAs($user)->postJson('/api/ventas-enviar-pendientes', ['codigo_motivo' => 1])
            ->assertOk()->assertJson(['reemitidas' => 1, 'validadas' => 2, 'restantes' => 0]);

        $this->assertSame(3, Venta::where('estado_siat', 'VALIDADA')->count());
    }

    public function test_importe_en_letras(): void
    {
        $this->assertSame('UN MIL DOSCIENTOS TREINTA Y CUATRO 50/100', FacturaCorreoService::literal(1234.5));
        $this->assertSame('CIEN 00/100', FacturaCorreoService::literal(100));
        $this->assertSame('VEINTIUN 07/100', FacturaCorreoService::literal(21.07));
    }

    public function test_clientes_crud_y_busqueda(): void
    {
        $user = $this->cajero();
        $user->givePermissionTo(['Ver Clientes', 'Crear Clientes', 'Editar Clientes', 'Eliminar Clientes']);
        $id = $this->actingAs($user)->postJson('/api/clientes', ['tipo_documento' => 'NIT', 'numero_documento' => '7308976010', 'nombre' => 'empresa'])
            ->assertCreated()->json('id');
        $this->actingAs($user)->postJson('/api/clientes', ['tipo_documento' => 'NIT', 'numero_documento' => '7308976010', 'nombre' => 'otra'])->assertStatus(422);
        $this->actingAs($user)->getJson('/api/clientes-buscar?q=73089')->assertJsonPath('0.nombre', 'EMPRESA');
        $this->actingAs($user)->deleteJson("/api/clientes/{$id}")->assertNoContent();
        // Al volver a registrarlo se revive el mismo cliente.
        $this->actingAs($user)->postJson('/api/clientes', ['tipo_documento' => 'NIT', 'numero_documento' => '7308976010', 'nombre' => 'empresa 2'])
            ->assertCreated()->assertJson(['id' => $id, 'nombre' => 'EMPRESA 2']);
    }
}

class FakeSiatClient extends SiatClient
{
    public array $calls = [];

    /** Operaciones que simulan "Impuestos no responde". */
    public array $offline = [];

    public bool $rechazar = false;

    public function call(string $service, string $method, array $payload): object
    {
        $this->calls[] = compact('service', 'method', 'payload');
        if (in_array($method, $this->offline, true)) {
            throw new SiatSinConexionException('sin conexión (prueba)');
        }

        return (object) match ($method) {
            'cuis' => ['codigo' => 'CUIS1', 'fechaVigencia' => now()->addYear()->toIso8601String(), 'transaccion' => true],
            'cufd' => ['codigo' => 'CUFD1', 'codigoControl' => 'CTRL1', 'direccion' => 'CALLE CHARCAS', 'fechaVigencia' => now()->addDay()->toIso8601String(), 'transaccion' => true],
            'recepcionFactura' => $this->rechazar
                ? ['transaccion' => false, 'codigoEstado' => 902, 'mensajesList' => (object) ['codigo' => 1, 'descripcion' => 'NIT INVALIDO']]
                : ['transaccion' => true, 'codigoEstado' => 908, 'codigoRecepcion' => 'REC-'.count($this->calls)],
            'anulacionFactura' => ['transaccion' => true, 'codigoEstado' => 905],
            'sincronizarFechaHora' => ['transaccion' => true, 'fechaHora' => now()->format('Y-m-d\TH:i:s.v')],
            'registroEventoSignificativo' => ['transaccion' => true, 'codigoRecepcionEventoSignificativo' => 'EVT-1'],
            'recepcionPaqueteFactura' => ['transaccion' => true, 'codigoEstado' => 901, 'codigoRecepcion' => 'PAQ-1'],
            'validacionRecepcionPaqueteFactura' => ['transaccion' => true, 'codigoEstado' => 908, 'codigoDescripcion' => 'VALIDADA'],
            default => ['transaccion' => true],
        };
    }

    public function token(): string
    {
        return 'token';
    }
}
