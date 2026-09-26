<?php

namespace App\Services\Siat;

use App\Models\Cliente;
use App\Models\Configuracion;
use App\Models\Producto;
use App\Models\SiatCatalogo;
use App\Models\SiatCufd;
use App\Models\Venta;
use Carbon\Carbon;
use DOMDocument;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Factura computarizada de compra y venta (documento sector 1, modalidad 2).
 *
 * Estados de `ventas.estado_siat`:
 * - PENDIENTE: no llegó a generarse (SIAT desactivado, sin CUFD o sin configuración);
 *   se puede volver a emitir.
 * - VALIDADA: el SIN la aceptó (908).
 * - OBSERVADA: el SIN respondió y la rechazó; se corrigen los datos y se reenvía.
 * - PENDIENTE_EVENTO: emitida fuera de línea (tipo de emisión 2) porque Impuestos no
 *   respondía; sale después en el paquete de un evento significativo.
 * - ANULADA: anulada ante el SIN.
 */
class FacturaService
{
    /** Respaldo si todavía no se sincronizó el catálogo del SIN (Impuestos → Catálogos). */
    public const MOTIVOS_ANULACION = [
        1 => 'FACTURA MAL EMITIDA',
        2 => 'NOTA DE CREDITO-DEBITO MAL EMITIDA',
        3 => 'DATOS DE EMISION INCORRECTOS',
        4 => 'FACTURA O NOTA DE CREDITO-DEBITO DEVUELTA',
    ];

    /** Motivos de anulación vigentes según la paramétrica sincronizada del SIN. */
    public static function motivosAnulacion(): array
    {
        $motivos = SiatCatalogo::where('tipo', 'MOTIVO_ANULACION')->pluck('descripcion', 'codigo')
            ->mapWithKeys(fn ($descripcion, $codigo) => [(int) $codigo => $descripcion])->sortKeys()->all();

        return $motivos ?: self::MOTIVOS_ANULACION;
    }

    /** Tipo de pago de la venta → método de pago paramétrico del SIN. */
    public const METODOS_PAGO = ['EFECTIVO' => 1, 'QR' => 7, 'COMBINADO' => 13];

    public const REEMITIBLES = ['PENDIENTE', 'OBSERVADA'];

    public function __construct(private SiatClient $client, private SiatService $siat, private CufGenerator $cufGenerator) {}

    public function emitir(Venta $sale): Venta
    {
        $sale->loadMissing('detalles');
        if (! config('siat.enabled')) {
            return $this->pendiente($sale, 'La facturación SIAT está desactivada (SIAT_ENABLED)');
        }

        try {
            // Venta cobrada en la caja sin conexión: se factura fuera de línea con la hora
            // real del cobro, si hay un CUFD que la cubra. Si no, sale en línea con la de hoy.
            if ($sale->fecha_offline && $cufd = $this->siat->cufdEn($sale->fecha_offline)) {
                return $this->fueraDeLinea($sale, $cufd, $sale->fecha_offline, 'Venta cobrada sin conexión: se envía en un evento significativo');
            }

            try {
                [$cuis, $cufd] = $this->siat->credenciales();
            } catch (SiatSinConexionException $exception) {
                $cufd = $this->siat->cufdVigente();
                if (! $cufd) {
                    return $this->pendiente($sale, 'Sin conexión con Impuestos y sin CUFD vigente: vuelva a emitirla cuando haya internet');
                }

                return $this->fueraDeLinea($sale, $cufd, now(), 'Impuestos no respondió al pedir credenciales: se envía en un evento significativo');
            }

            $date = now();
            $xml = $this->armar($sale, $cufd, $date, 1);
            $archivo = gzencode($xml, 9);
            try {
                $response = $this->client->call('ServicioFacturacionCompraVenta', 'recepcionFactura', [
                    'SolicitudServicioRecepcionFactura' => $this->solicitudFactura($cuis->codigo, $cufd->codigo, 1) + [
                        'archivo' => $archivo, 'fechaEnvio' => $date->format('Y-m-d\TH:i:s.v'), 'hashArchivo' => hash('sha256', $archivo),
                    ],
                ]);
            } catch (SiatSinConexionException) {
                return $this->fueraDeLinea($sale, $cufd, $date, 'Impuestos no respondió: se envía en un evento significativo');
            }

            $validada = (int) ($response->codigoEstado ?? 0) === 908;
            $sale->update([
                'estado_siat' => $validada ? 'VALIDADA' : 'OBSERVADA',
                'codigo_recepcion' => $response->codigoRecepcion ?? null,
                'siat_mensaje' => $validada ? null : (SiatClient::mensaje($response) ?: ($response->codigoDescripcion ?? 'Rechazada por Impuestos')),
            ]);
        } catch (\Throwable $exception) {
            report($exception);

            return $this->pendiente($sale, $exception->getMessage());
        }

        return $sale->fresh();
    }

    public function anular(Venta $sale, int $motivo): array
    {
        abort_unless($sale->cuf && $sale->estado_siat === 'VALIDADA', 422, 'Sólo se puede anular en Impuestos una factura validada');
        [$cuis, $cufd] = $this->siat->credenciales();
        $response = $this->client->call('ServicioFacturacionCompraVenta', 'anulacionFactura', [
            'SolicitudServicioAnulacionFactura' => $this->solicitudFactura($cuis->codigo, $cufd->codigo, (int) ($sale->tipo_emision ?: 1)) + [
                'codigoMotivo' => $motivo, 'cuf' => $sale->cuf,
            ],
        ]);
        $ok = (bool) ($response->transaccion ?? false);
        $mensaje = SiatClient::mensaje($response) ?: ($response->codigoDescripcion ?? null);
        if ($ok) {
            $sale->update(['estado_siat' => 'ANULADA', 'siat_mensaje' => $mensaje]);
        }

        return ['anulada' => $ok, 'mensaje' => $mensaje ?: ($ok ? 'Factura anulada en Impuestos' : 'Impuestos rechazó la anulación')];
    }

    /** Consulta al SIN el estado real de la factura y lo guarda. */
    public function verificar(Venta $sale): array
    {
        abort_unless($sale->cuf, 422, 'La venta no tiene CUF para consultar en Impuestos');
        [$cuis, $cufd] = $this->siat->credenciales();
        $response = $this->client->call('ServicioFacturacionCompraVenta', 'verificacionEstadoFactura', [
            'SolicitudServicioVerificacionEstadoFactura' => $this->solicitudFactura($cuis->codigo, $cufd->codigo, (int) ($sale->tipo_emision ?: 1)) + ['cuf' => $sale->cuf],
        ]);
        $code = (int) ($response->codigoEstado ?? 0);
        $estado = match (true) {
            in_array($code, [690, 908], true) => 'VALIDADA',
            in_array($code, [691, 905], true) => 'ANULADA',
            default => null,
        };
        $mensaje = $response->codigoDescripcion ?? SiatClient::mensaje($response);
        if ($estado) {
            $sale->update(['estado_siat' => $estado, 'siat_mensaje' => $estado === 'VALIDADA' ? null : $mensaje]);
        }

        return ['codigo_estado' => $code ?: null, 'estado_siat' => $sale->fresh()->estado_siat, 'mensaje' => $mensaje];
    }

    /** Regenera CUF y XML con tipo de emisión 2 y la deja lista para un evento significativo. */
    public function fueraDeLinea(Venta $sale, SiatCufd $cufd, Carbon $date, string $mensaje): Venta
    {
        $this->armar($sale, $cufd, $date, 2);
        $sale->update(['estado_siat' => 'PENDIENTE_EVENTO', 'siat_mensaje' => $mensaje, 'codigo_recepcion' => null]);

        return $sale->fresh();
    }

    public function xmlPath(Venta $sale): string
    {
        return "siat/facturas/{$sale->id}.xml";
    }

    /** Genera CUF + XML, lo valida contra el XSD y lo guarda. Devuelve el XML. */
    private function armar(Venta $sale, SiatCufd $cufd, Carbon $date, int $emision): string
    {
        abort_unless($sale->numero_factura, 422, 'La venta no tiene número de factura');
        $date = $date->copy()->setTimezone(config('app.timezone'));
        $timestamp = $date->format('YmdHis').$date->format('v');
        $cuf = $this->cufGenerator->generate(
            $this->siat->nit(), $timestamp, config('siat.sucursal'), config('siat.modalidad'),
            $emision, (int) $sale->numero_factura, config('siat.punto_venta'), $cufd->codigo_control,
        );
        $codigos = $this->codigosSin($sale);
        $leyenda = $sale->leyenda ?: $this->siat->leyenda($codigos->first()['actividad'] ?? config('siat.actividad_economica'));
        $xml = $this->xml($sale, $cufd, $cuf, $date, $leyenda, $codigos);
        $this->validarXsd($xml);
        Storage::disk('local')->put($this->xmlPath($sale), $xml);

        $sale->update([
            'cuf' => $cuf, 'cufd' => $cufd->codigo, 'tipo_emision' => $emision, 'fecha_emision_siat' => $date,
            'leyenda' => $leyenda, 'xml_path' => $this->xmlPath($sale),
        ]);

        return $xml;
    }

    private function pendiente(Venta $sale, string $mensaje): Venta
    {
        error_log('[SIAT] Factura pendiente: '.json_encode(['venta_id' => $sale->id, 'mensaje' => $mensaje], JSON_UNESCAPED_UNICODE));
        $sale->update(['estado_siat' => 'PENDIENTE', 'siat_mensaje' => $mensaje]);

        return $sale->fresh();
    }

    /** Actividad y producto SIN de cada línea, tomados de la categoría del producto. */
    private function codigosSin(Venta $sale)
    {
        $products = Producto::withTrashed()->with('categoriaRelacion')
            ->whereIn('id', $sale->detalles->pluck('producto_id'))->get()->keyBy('id');

        return $sale->detalles->mapWithKeys(fn ($item) => [$item->id => [
            'actividad' => $products->get($item->producto_id)?->categoriaRelacion?->actividad_economica ?: config('siat.actividad_economica'),
            'producto' => $products->get($item->producto_id)?->categoriaRelacion?->codigo_producto_sin ?: config('siat.codigo_producto_sin'),
        ]]);
    }

    private function xml(Venta $sale, SiatCufd $cufd, string $cuf, Carbon $date, string $leyenda, $codigos): string
    {
        $company = Configuracion::first();
        [$lines, $total, $additionalDiscount] = $this->montos($sale);
        $document = trim((string) $sale->numero_documento);
        $anonymous = in_array($document, ['', '0', Cliente::DOCUMENTO_SIN_DATOS], true);
        $document = $anonymous ? Cliente::DOCUMENTO_SIN_DATOS : $document;

        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;
        $root = $doc->createElement('facturaComputarizadaCompraVenta');
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
        $root->setAttributeNS('http://www.w3.org/2001/XMLSchema-instance', 'xsi:noNamespaceSchemaLocation', 'facturaComputarizadaCompraVenta.xsd');
        $doc->appendChild($root);

        $this->campos($doc, $root->appendChild($doc->createElement('cabecera')), [
            'nitEmisor' => $this->siat->nit(),
            'razonSocialEmisor' => $company?->nombre_empresa ?: 'BAJO CERO',
            'municipio' => config('siat.municipio'),
            'telefono' => $company?->telefono ?: null,
            'numeroFactura' => $sale->numero_factura,
            'cuf' => $cuf,
            'cufd' => $cufd->codigo,
            'codigoSucursal' => config('siat.sucursal'),
            'direccion' => $cufd->direccion ?: ($company?->direccion ?: 'S/D'),
            'codigoPuntoVenta' => config('siat.punto_venta'),
            'fechaEmision' => $date->format('Y-m-d\TH:i:s.v'),
            'nombreRazonSocial' => $anonymous ? Cliente::NOMBRE_SIN_DATOS : ($sale->cliente_nombre ?: Cliente::NOMBRE_SIN_DATOS),
            'codigoTipoDocumentoIdentidad' => Cliente::TIPOS_DOCUMENTO[$sale->tipo_documento ?: 'CI'] ?? 1,
            'numeroDocumento' => $document,
            'complemento' => $sale->complemento ?: null,
            'codigoCliente' => $sale->cliente_id ? (string) $sale->cliente_id : $document,
            'codigoMetodoPago' => self::METODOS_PAGO[$sale->tipo_pago] ?? 1,
            'numeroTarjeta' => null,
            'montoTotal' => $this->money($total),
            'montoTotalSujetoIva' => $this->money($total),
            'codigoMoneda' => 1,
            'tipoCambio' => '1',
            'montoTotalMoneda' => $this->money($total),
            'montoGiftCard' => null,
            'descuentoAdicional' => $this->money($additionalDiscount),
            'codigoExcepcion' => $sale->codigo_excepcion ?: null,
            'cafc' => null,
            'leyenda' => $leyenda,
            'usuario' => mb_substr($sale->usuario?->username ?: ($sale->usuario_nombre ?: 'cajero'), 0, 100),
            'codigoDocumentoSector' => 1,
        ]);

        foreach ($lines as $line) {
            $item = $line['item'];
            $this->campos($doc, $root->appendChild($doc->createElement('detalle')), [
                'actividadEconomica' => $codigos[$item->id]['actividad'],
                'codigoProductoSin' => $codigos[$item->id]['producto'],
                'codigoProducto' => mb_substr($item->codigo ?: (string) $item->producto_id, 0, 50),
                'descripcion' => mb_substr($item->nombre, 0, 500),
                'cantidad' => $this->money($line['cantidad']),
                'unidadMedida' => $item->unidad === 'KG' ? config('siat.unidad_kg') : config('siat.unidad_pieza'),
                'precioUnitario' => $this->money($line['precioUnitario']),
                'montoDescuento' => $this->money($line['montoDescuento']),
                'subTotal' => $this->money($line['subTotal']),
                'numeroSerie' => null,
                'numeroImei' => null,
            ]);
        }

        return $doc->saveXML();
    }

    /**
     * El XSD sólo admite 2 decimales en cantidad y precio, mientras la venta guarda
     * 3 de cantidad (kilos) y 4 de precio. Se redondea manteniendo las igualdades que
     * valida el SIN:
     *   cantidad × precioUnitario − montoDescuento = subTotal
     *   Σ subTotal − descuentoAdicional = montoTotal
     * El descuento de la venta se declara una sola vez, en descuentoAdicional; el
     * montoDescuento de la línea sólo absorbe el residuo del redondeo.
     */
    private function montos(Venta $sale): array
    {
        $lines = [];
        $sum = 0.0;
        foreach ($sale->detalles as $item) {
            $quantity = max(round((float) $item->cantidad, 2), 0.01);
            $subTotal = round((float) $item->subtotal, 2);
            if ($subTotal <= 0) {
                // El SIN no acepta subTotal cero: se factura el mínimo y se compensa en descuentoAdicional.
                $subTotal = round($quantity * 0.01, 2);
            }
            // Hacia arriba, para que el residuo sea un descuento ≥ 0.
            $unitPrice = ceil(round($subTotal / $quantity * 100, 6)) / 100;
            $lines[] = [
                'item' => $item, 'cantidad' => $quantity, 'precioUnitario' => $unitPrice,
                'montoDescuento' => round($quantity * $unitPrice - $subTotal, 2), 'subTotal' => $subTotal,
            ];
            $sum = round($sum + $subTotal, 2);
        }
        $total = round((float) $sale->total, 2);
        $additionalDiscount = round($sum - $total, 2);
        if ($additionalDiscount < 0) {
            $total = $sum;
            $additionalDiscount = 0.0;
        }
        if ($total <= 0) {
            throw new RuntimeException('El monto total de la factura debe ser mayor a cero');
        }
        if (count($lines) > 500) {
            throw new RuntimeException('Una factura admite como máximo 500 líneas');
        }

        return [$lines, $total, $additionalDiscount];
    }

    private function money(float $value): string
    {
        return number_format($value, 2, '.', '');
    }

    private function campos(DOMDocument $doc, \DOMElement $parent, array $fields): void
    {
        foreach ($fields as $name => $value) {
            $element = $doc->createElement($name);
            if ($value === null) {
                $element->setAttributeNS('http://www.w3.org/2001/XMLSchema-instance', 'xsi:nil', 'true');
            } else {
                $element->appendChild($doc->createTextNode((string) $value));
            }
            $parent->appendChild($element);
        }
    }

    private function validarXsd(string $xml): void
    {
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $doc = new DOMDocument;
        $valid = $doc->loadXML($xml, LIBXML_NONET) && $doc->schemaValidate(resource_path('siat/facturaComputarizadaCompraVenta.xsd'));
        $errors = array_map(fn ($error) => trim($error->message), libxml_get_errors());
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (! $valid) {
            throw new RuntimeException('XML inválido según XSD: '.implode('; ', $errors));
        }
    }

    public function solicitudFactura(string $cuis, string $cufd, int $emision): array
    {
        return $this->siat->solicitud($cuis, withModalidad: true) + [
            'codigoDocumentoSector' => 1, 'codigoEmision' => $emision, 'cufd' => $cufd, 'tipoFacturaDocumento' => 1,
        ];
    }
}
