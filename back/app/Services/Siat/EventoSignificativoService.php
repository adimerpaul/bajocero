<?php

namespace App\Services\Siat;

use App\Models\SiatEventoSignificativo;
use App\Models\Venta;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use PharData;
use RuntimeException;

/**
 * Envío de las facturas emitidas fuera de línea (PENDIENTE_EVENTO).
 *
 * Por cada CUFD con facturas pendientes: se registra un evento significativo que
 * cubre desde la primera hasta la última emisión (cufdEvento = el CUFD con que se
 * emitieron, cufd = el vigente hoy), se mandan en paquetes tar.gz de hasta 500 XML y
 * se valida cada paquete. Sin CAFC: los motivos 1 a 4 corresponden a facturas que
 * el propio sistema emitió sin conexión.
 */
class EventoSignificativoService
{
    public const MOTIVOS = [
        1 => 'CORTE DEL SERVICIO DE INTERNET',
        2 => 'INACCESIBILIDAD AL SERVICIO WEB DE LA ADMINISTRACIÓN TRIBUTARIA',
        3 => 'INGRESO A ZONAS SIN INTERNET POR DESPLIEGUE DE PUNTO DE VENTA',
        4 => 'VENTA EN LUGARES SIN INTERNET',
    ];

    public function __construct(private SiatClient $client, private SiatService $siat, private FacturaService $facturas) {}

    public function pendientes()
    {
        return Venta::where('tipo_comprobante', 'FACTURA')->where('estado_siat', 'PENDIENTE_EVENTO')
            ->whereNotNull('cuf')->whereNotNull('xml_path');
    }

    /** @return SiatEventoSignificativo[] */
    public function enviarPendientes(int $motivo, ?string $descripcion, ?int $userId): array
    {
        set_time_limit(600);
        $grupos = $this->pendientes()->orderBy('fecha_emision_siat')->orderBy('id')->get()->groupBy('cufd');
        abort_if($grupos->isEmpty(), 422, 'No hay facturas pendientes de envío');

        [$cuis, $cufd] = $this->siat->credenciales();
        $this->esperarRelojSiat($grupos->flatten()->max('fecha_emision_siat'), $cuis->codigo);
        $eventos = [];
        foreach ($grupos as $cufdEvento => $ventas) {
            $eventos[] = $this->procesar($ventas, $motivo, $descripcion ?: self::MOTIVOS[$motivo], $cuis->codigo, $cufd->codigo, (string) $cufdEvento, $userId);
        }

        return $eventos;
    }

    /** Vuelve a consultar un paquete que quedó en validación. */
    public function revalidar(SiatEventoSignificativo $evento): SiatEventoSignificativo
    {
        abort_unless($evento->codigo_recepcion, 422, 'El evento no tiene un paquete recibido');
        [$cuis] = $this->siat->credenciales();
        $this->validar($evento, $cuis->codigo, $evento->ventas()->orderBy('fecha_emision_siat')->orderBy('id')->get());

        return $evento->fresh();
    }

    /**
     * El SIN rechaza un evento cuyo fin está en su futuro ("RANGO DE FECHAS ... INVALIDO").
     * Si el reloj del servidor va adelantado respecto al del SIN y la última factura
     * es de hace segundos, se espera lo justo antes de registrar el evento.
     */
    private function esperarRelojSiat(Carbon $ultimaEmision, string $cuis): void
    {
        $siatNow = $this->siat->fechaHoraSiat($cuis);
        if (! $siatNow) {
            return;
        }
        $wait = (int) ceil($siatNow->diffInSeconds($ultimaEmision->copy()->addSeconds(2), false));
        abort_if($wait > 90, 422, "La última factura pendiente es de hace muy poco según el reloj de Impuestos: intente de nuevo en {$wait} segundos");
        if ($wait > 0) {
            sleep($wait);
        }
    }

    private function procesar(Collection $ventas, int $motivo, string $descripcion, string $cuis, string $cufd, string $cufdEvento, ?int $userId): SiatEventoSignificativo
    {
        $inicio = $ventas->min('fecha_emision_siat')->copy();
        $fin = $ventas->max('fecha_emision_siat')->copy()->addSecond();
        if ($fin->isFuture()) {
            $fin = now();
        }
        $evento = SiatEventoSignificativo::create([
            'codigo_motivo' => $motivo, 'descripcion' => mb_substr($descripcion, 0, 500), 'inicio' => $inicio, 'fin' => $fin,
            'cufd' => $cufd, 'cufd_evento' => $cufdEvento, 'cantidad_facturas' => $ventas->count(), 'user_id' => $userId,
        ]);
        Venta::whereIn('id', $ventas->pluck('id'))->update(['siat_evento_id' => $evento->id]);

        try {
            $response = $this->client->call('FacturacionOperaciones', 'registroEventoSignificativo', [
                'SolicitudEventoSignificativo' => $this->siat->solicitud($cuis) + [
                    'codigoMotivoEvento' => $motivo, 'descripcion' => $evento->descripcion,
                    'cufd' => $cufd, 'cufdEvento' => $cufdEvento,
                    'fechaHoraInicioEvento' => $inicio->format('Y-m-d\TH:i:s.v'),
                    'fechaHoraFinEvento' => $fin->format('Y-m-d\TH:i:s.v'),
                ],
            ]);
            if (! ($response->transaccion ?? false) || empty($response->codigoRecepcionEventoSignificativo)) {
                throw new RuntimeException(SiatClient::mensaje($response) ?: 'Impuestos rechazó el evento significativo');
            }
            $evento->update(['codigo_evento' => (string) $response->codigoRecepcionEventoSignificativo, 'estado' => 'REGISTRADO']);

            // Un paquete admite hasta 500 facturas; todos van con el mismo código de evento.
            $recepciones = [];
            foreach ($ventas->chunk(config('siat.paquete_maximo')) as $index => $paquete) {
                $recepciones[] = $this->enviarPaquete($evento, $paquete->values(), $cuis, $index);
            }
            $evento->update(['codigo_recepcion' => implode(',', $recepciones), 'estado' => 'EN_VALIDACION']);
            $this->validar($evento, $cuis, $ventas);
        } catch (\Throwable $exception) {
            report($exception);
            $evento->update(['estado' => 'ERROR', 'mensaje' => $exception->getMessage()]);
            // Las facturas siguen pendientes para un nuevo intento.
            Venta::whereIn('id', $ventas->pluck('id'))->update(['siat_evento_id' => null]);
        }

        return $evento->fresh();
    }

    private function enviarPaquete(SiatEventoSignificativo $evento, Collection $ventas, string $cuis, int $index): string
    {
        $directory = storage_path('app/private/siat/paquetes');
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
        // Nombre único: PharData recuerda los archivos por ruta dentro del mismo proceso,
        // y reutilizar una ruta ya borrada falla. El paquete se borra apenas se lee.
        $tar = "{$directory}/evento_{$evento->id}_{$index}_".uniqid().'.tar';
        try {
            $archive = new PharData($tar);
            foreach ($ventas as $venta) {
                $archive->addFromString("factura_{$venta->numero_factura}.xml", Storage::disk('local')->get($venta->xml_path));
            }
            $archive->compress(\Phar::GZ);
            unset($archive);
            $archivo = file_get_contents("{$tar}.gz");
        } finally {
            foreach ([$tar, "{$tar}.gz"] as $path) {
                if (is_file($path)) {
                    @unlink($path);
                }
            }
        }

        $response = $this->client->call('ServicioFacturacionCompraVenta', 'recepcionPaqueteFactura', [
            'SolicitudServicioRecepcionPaquete' => $this->facturas->solicitudFactura($cuis, $evento->cufd, 2) + [
                'archivo' => $archivo, 'fechaEnvio' => now()->format('Y-m-d\TH:i:s.v'), 'hashArchivo' => hash('sha256', $archivo),
                'cantidadFacturas' => $ventas->count(), 'codigoEvento' => $evento->codigo_evento, 'cafc' => null,
            ],
        ]);
        if (empty($response->codigoRecepcion)) {
            throw new RuntimeException(SiatClient::mensaje($response) ?: 'Impuestos no recibió el paquete');
        }

        return (string) $response->codigoRecepcion;
    }

    /** Consulta la validación de cada paquete: 908 VALIDADA, 904 OBSERVADA, 901 PENDIENTE. */
    private function validar(SiatEventoSignificativo $evento, string $cuis, Collection $ventas): void
    {
        $chunks = $ventas->chunk(config('siat.paquete_maximo'))->values();
        $estados = [];
        $mensajes = [];
        foreach (array_filter(explode(',', (string) $evento->codigo_recepcion)) as $index => $recepcion) {
            $response = null;
            for ($attempt = 1; $attempt <= 8; $attempt++) {
                if ($attempt > 1) {
                    sleep(2);
                }
                $response = $this->client->call('ServicioFacturacionCompraVenta', 'validacionRecepcionPaqueteFactura', [
                    'SolicitudServicioValidacionRecepcionPaquete' => $this->facturas->solicitudFactura($cuis, $evento->cufd, 2) + ['codigoRecepcion' => $recepcion],
                ]);
                if ((int) ($response->codigoEstado ?? 0) !== 901) {
                    break;
                }
            }
            $code = (int) ($response->codigoEstado ?? 0);
            $estado = match ($code) {
                908 => 'VALIDADO', 901 => 'EN_VALIDACION', default => 'OBSERVADO',
            };
            $estados[] = $estado;
            $mensaje = SiatClient::mensaje($response) ?: ($response->codigoDescripcion ?? null);
            if ($mensaje) {
                $mensajes[] = $mensaje;
            }
            $ids = ($chunks[$index] ?? collect())->pluck('id');
            if ($estado === 'VALIDADO') {
                Venta::whereIn('id', $ids)->update(['estado_siat' => 'VALIDADA', 'codigo_recepcion' => $recepcion, 'siat_mensaje' => null]);
            } elseif ($estado === 'OBSERVADO') {
                Venta::whereIn('id', $ids)->update(['estado_siat' => 'OBSERVADA', 'codigo_recepcion' => $recepcion, 'siat_mensaje' => $mensaje ?: 'Paquete observado por Impuestos']);
            }
        }
        $estado = in_array('OBSERVADO', $estados, true) ? 'OBSERVADO' : (in_array('EN_VALIDACION', $estados, true) ? 'EN_VALIDACION' : 'VALIDADO');
        $evento->update(['estado' => $estado, 'mensaje' => $mensajes ? implode(' | ', array_unique($mensajes)) : null]);
    }
}
