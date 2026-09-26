<?php

namespace App\Services\Siat;

use App\Models\Configuracion;
use App\Models\SiatCatalogo;
use App\Models\SiatCufd;
use App\Models\SiatCuis;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Códigos del SIN (CUIS y CUFD), sincronización de catálogos y consultas sueltas.
 *
 * CUIS: código único de inicio de sistema, vigente un año por sucursal/punto de venta.
 * CUFD: código único de facturación diaria, vence cada 24 h y trae el código de
 * control que se pega al final de cada CUF.
 */
class SiatService
{
    /** Margen para no emitir con un código que vence mientras viaja la factura. */
    private const MARGEN_MINUTOS = 5;

    public const LEYENDA_POR_DEFECTO = 'Ley N° 453: Tienes derecho a recibir información sobre las características y contenidos de los productos que consumes.';

    public function __construct(private SiatClient $client) {}

    public function cuisVigente(): ?SiatCuis
    {
        return SiatCuis::where('vence_en', '>', now()->addMinutes(self::MARGEN_MINUTOS))
            ->where('sucursal', config('siat.sucursal'))->where('punto_venta', config('siat.punto_venta'))
            ->latest('id')->first();
    }

    public function cufdVigente(): ?SiatCufd
    {
        return SiatCufd::where('vence_en', '>', now()->addMinutes(self::MARGEN_MINUTOS))
            ->where('sucursal', config('siat.sucursal'))->where('punto_venta', config('siat.punto_venta'))
            ->latest('id')->first();
    }

    /** CUFD con el que se pudo emitir en ese momento (para facturas fuera de línea). */
    public function cufdEn(Carbon $moment): ?SiatCufd
    {
        return SiatCufd::where('created_at', '<=', $moment)->where('vence_en', '>', $moment)
            ->where('sucursal', config('siat.sucursal'))->where('punto_venta', config('siat.punto_venta'))
            ->latest('id')->first();
    }

    /** CUIS: si el SIN ya tiene uno vigente lo devuelve igual (mensaje 980) y se guarda. */
    public function obtenerCuis(bool $forzar = false): SiatCuis
    {
        if (! $forzar && $cuis = $this->cuisVigente()) {
            return $cuis;
        }
        $response = $this->client->call('FacturacionCodigos', 'cuis', ['SolicitudCuis' => $this->solicitud(withCuis: false, withModalidad: true)]);
        if (empty($response->codigo) || empty($response->fechaVigencia)) {
            throw new RuntimeException(SiatClient::mensaje($response) ?: 'Impuestos no devolvió el CUIS');
        }

        return SiatCuis::create([
            'codigo' => $response->codigo, 'vence_en' => Carbon::parse($response->fechaVigencia)->setTimezone(config('app.timezone')),
            'sucursal' => config('siat.sucursal'), 'punto_venta' => config('siat.punto_venta'),
        ]);
    }

    public function obtenerCufd(bool $forzar = false): SiatCufd
    {
        if (! $forzar && $cufd = $this->cufdVigente()) {
            return $cufd;
        }
        $cuis = $this->obtenerCuis();
        $response = $this->client->call('FacturacionCodigos', 'cufd', ['SolicitudCufd' => $this->solicitud($cuis->codigo, withModalidad: true)]);
        if (! ($response->transaccion ?? false) || empty($response->codigo)) {
            throw new RuntimeException(SiatClient::mensaje($response) ?: 'Impuestos no devolvió el CUFD');
        }

        return SiatCufd::create([
            'codigo' => $response->codigo, 'codigo_control' => $response->codigoControl,
            'direccion' => $response->direccion ?? null,
            'vence_en' => Carbon::parse($response->fechaVigencia)->setTimezone(config('app.timezone')),
            'sucursal' => config('siat.sucursal'), 'punto_venta' => config('siat.punto_venta'),
        ]);
    }

    /** @return array{0: SiatCuis, 1: SiatCufd} */
    public function credenciales(): array
    {
        return [$this->obtenerCuis(), $this->obtenerCufd()];
    }

    public function verificarComunicacion(): array
    {
        try {
            $response = $this->client->call('FacturacionCodigos', 'verificarComunicacion', []);

            return ['online' => (bool) ($response->transaccion ?? false), 'mensaje' => SiatClient::mensaje($response) ?: 'Comunicación correcta'];
        } catch (\Throwable $exception) {
            return ['online' => false, 'mensaje' => $exception->getMessage()];
        }
    }

    /** Hora oficial del SIN; null si no respondió. */
    public function fechaHoraSiat(?string $cuis = null): ?Carbon
    {
        try {
            $response = $this->client->call('FacturacionSincronizacion', 'sincronizarFechaHora', ['SolicitudSincronizacion' => $this->solicitud($cuis ?? $this->obtenerCuis()->codigo)]);

            return empty($response->fechaHora) ? null : Carbon::parse($response->fechaHora, config('app.timezone'));
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * true/false según el padrón; null si no se pudo consultar (se factura igual y
     * el SIN observará la factura si el NIT fuera inválido).
     */
    public function verificarNit(string $nit): ?bool
    {
        if (! ctype_digit($nit)) {
            return false;
        }
        try {
            $cuis = $this->obtenerCuis();
            $response = $this->client->call('FacturacionCodigos', 'verificarNit', ['SolicitudVerificarNit' => $this->solicitud($cuis->codigo, withModalidad: true) + ['nitParaVerificacion' => $nit]]);
        } catch (\Throwable) {
            return null;
        }
        $codes = array_map(fn ($m) => (int) ($m->codigo ?? 0), SiatClient::lista($response->mensajesList ?? null));

        // 986 = NIT ACTIVO.
        return in_array(986, $codes, true);
    }

    /** Copia local de las paramétricas que usa el sistema. */
    public function sincronizar(): array
    {
        $cuis = $this->obtenerCuis();
        $request = ['SolicitudSincronizacion' => $this->solicitud($cuis->codigo)];
        $call = fn (string $method) => $this->client->call('FacturacionSincronizacion', $method, $request);
        $rows = [];

        foreach (SiatClient::lista($call('sincronizarActividades')->listaActividades ?? null) as $item) {
            $rows[] = ['tipo' => 'ACTIVIDAD', 'codigo' => $item->codigoCaeb, 'codigo_actividad' => $item->tipoActividad ?? null, 'descripcion' => $item->descripcion];
        }
        foreach (SiatClient::lista($call('sincronizarListaProductosServicios')->listaCodigos ?? null) as $item) {
            $rows[] = ['tipo' => 'PRODUCTO', 'codigo' => $item->codigoProducto, 'codigo_actividad' => $item->codigoActividad, 'descripcion' => trim($item->descripcionProducto)];
        }
        foreach (SiatClient::lista($call('sincronizarListaLeyendasFactura')->listaLeyendas ?? null) as $item) {
            $rows[] = ['tipo' => 'LEYENDA', 'codigo' => null, 'codigo_actividad' => $item->codigoActividad, 'descripcion' => trim($item->descripcionLeyenda)];
        }
        $parametricas = [
            'UNIDAD_MEDIDA' => 'sincronizarParametricaUnidadMedida',
            'DOCUMENTO_IDENTIDAD' => 'sincronizarParametricaTipoDocumentoIdentidad',
            'EVENTO' => 'sincronizarParametricaEventosSignificativos',
            'MOTIVO_ANULACION' => 'sincronizarParametricaMotivoAnulacion',
            'METODO_PAGO' => 'sincronizarParametricaTipoMetodoPago',
        ];
        foreach ($parametricas as $tipo => $method) {
            foreach (SiatClient::lista($call($method)->listaCodigos ?? null) as $item) {
                $rows[] = ['tipo' => $tipo, 'codigo' => $item->codigoClasificador, 'codigo_actividad' => null, 'descripcion' => $item->descripcion];
            }
        }
        abort_if(! $rows, 422, 'Impuestos no devolvió catálogos');

        DB::transaction(function () use ($rows) {
            SiatCatalogo::query()->delete();
            foreach (array_chunk($rows, 200) as $chunk) {
                SiatCatalogo::insert(array_map(fn ($row) => $row + ['created_at' => now(), 'updated_at' => now()], $chunk));
            }
        });

        return SiatCatalogo::selectRaw('tipo, count(*) as cantidad')->groupBy('tipo')->pluck('cantidad', 'tipo')->all();
    }

    /** Leyenda de la Ley 453 al azar entre las de la actividad, como pide el SIN. */
    public function leyenda(string $actividad): string
    {
        return SiatCatalogo::where('tipo', 'LEYENDA')->where('codigo_actividad', $actividad)->inRandomOrder()->value('descripcion')
            ?: self::LEYENDA_POR_DEFECTO;
    }

    public function nit(): string
    {
        $nit = config('siat.nit') ?: Configuracion::first()?->nit;
        if (! $nit || ! config('siat.codigo_sistema')) {
            throw new RuntimeException('Faltan el NIT o el código de sistema SIAT (SIAT_NIT / SIAT_CODIGO_SISTEMA)');
        }

        return (string) $nit;
    }

    /** Campos comunes de toda solicitud al SIN. */
    public function solicitud(?string $cuis = null, bool $withCuis = true, bool $withModalidad = false): array
    {
        $data = [
            'codigoAmbiente' => config('siat.ambiente'), 'codigoPuntoVenta' => config('siat.punto_venta'),
            'codigoSistema' => config('siat.codigo_sistema'), 'codigoSucursal' => config('siat.sucursal'),
            'nit' => $this->nit(),
        ];
        if ($withModalidad) {
            $data['codigoModalidad'] = config('siat.modalidad');
        }
        if ($withCuis) {
            $data['cuis'] = $cuis;
        }

        return $data;
    }
}
