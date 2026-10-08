<?php

namespace App\Services\Siat;

use App\Mail\FacturaMail;
use App\Models\Configuracion;
use App\Models\Venta;
use Barryvdh\DomPDF\Facade\Pdf;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\PngWriter;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/**
 * PDF de la factura y correo al cliente. El PDF nunca se escribe en disco: se arma
 * en memoria cada vez que se envía o se descarga, para no ocupar espacio en el
 * servidor. El XML sí se conserva porque es el documento fiscal.
 */
class FacturaCorreoService
{
    /** Contenido binario del PDF (rollo de 80 mm). */
    public function pdf(Venta $sale): string
    {
        $sale->loadMissing('detalles');
        $qr = (new Builder(writer: new PngWriter, data: (string) $sale->factura_url, size: 240, margin: 4))->build()->getDataUri();

        return Pdf::loadView('facturas.computarizada', [
            'sale' => $sale, 'company' => Configuracion::first(), 'qr' => $qr, 'literal' => self::literal((float) $sale->total),
        ])->setPaper([0, 0, 226.77, 841.89])->setOption('isFontSubsettingEnabled', true)->output();
    }

    /** Envía PDF + XML al cliente. false si no hay correo o si falló (el error queda en email_error). */
    public function enviar(Venta $sale, ?string $email = null): bool
    {
        $email = $email ?: $sale->cliente_email ?: $sale->cliente?->email;
        if (! $email || ! $sale->cuf) {
            return false;
        }

        return $this->mandar($sale, $email, new FacturaMail($sale, $this->empresa(), $this->pdf($sale), $this->obtenerXml($sale)));
    }

    public function enviarAnulacion(Venta $sale, ?string $motivo): bool
    {
        $email = $sale->cliente_email ?: $sale->cliente?->email;
        if (! $email || ! $sale->cuf) {
            return false;
        }

        return $this->mandar($sale, $email, new FacturaMail($sale, $this->empresa(), $this->pdf($sale), $this->obtenerXml($sale), true, $motivo));
    }

    public function enviarReversion(Venta $sale): bool
    {
        $email = $sale->cliente_email ?: $sale->cliente?->email;
        if (! $email || ! $sale->cuf) {
            return false;
        }

        return $this->mandar($sale, $email, new FacturaMail($sale, $this->empresa(), $this->pdf($sale), $this->obtenerXml($sale), false, null, true));
    }

    /** Obtiene el XML oficial de la factura desde disco o lo reconstruye si no estuviera disponible. */
    public function obtenerXml(Venta $sale): ?string
    {
        $path = ltrim($sale->xml_path ?: "siat/facturas/{$sale->id}.xml", '/');
        if (Storage::disk('local')->exists($path)) {
            return Storage::disk('local')->get($path);
        }

        if ($sale->cuf && $sale->numero_factura) {
            try {
                return app(FacturaService::class)->reconstruirXml($sale);
            } catch (\Throwable $exception) {
                report($exception);
            }
        }

        return null;
    }

    /** Se manda después de responder: el cajero no espera al servidor de correo. */
    public function enviarDespues(Venta $sale): void
    {
        if (($sale->cliente_email || $sale->cliente?->email) && in_array($sale->estado_siat, ['VALIDADA', 'PENDIENTE_EVENTO'], true)) {
            $id = $sale->id;
            dispatch(fn () => app(self::class)->enviar(Venta::find($id)))->afterResponse();
        }
    }

    private function mandar(Venta $sale, string $email, FacturaMail $mail): bool
    {
        try {
            Mail::to($email)->send($mail);
            $sale->update(['email_enviado_en' => now(), 'email_error' => null]);
            error_log('[CORREO][FACTURA] Enviado: '.json_encode(['venta_id' => $sale->id, 'destino' => $email, 'anulada' => $mail->anulada, 'revertida' => $mail->revertida], JSON_UNESCAPED_UNICODE));

            return true;
        } catch (\Throwable $exception) {
            report($exception);
            $sale->update(['email_error' => $exception->getMessage()]);

            return false;
        }
    }

    private function empresa(): string
    {
        return Configuracion::first()?->nombre_empresa ?: 'Bajo Cero';
    }

    /** 1234.5 → "UN MIL DOSCIENTOS TREINTA Y CUATRO 50/100" (sin depender de la extensión intl). */
    public static function literal(float $amount): string
    {
        $cents = (int) round($amount * 100);

        return self::entero(intdiv($cents, 100)).' '.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT).'/100';
    }

    private static function entero(int $n): string
    {
        if ($n === 0) {
            return 'CERO';
        }
        $millones = intdiv($n, 1000000);
        $miles = intdiv($n % 1000000, 1000);
        $resto = $n % 1000;

        return implode(' ', array_filter([
            $millones ? ($millones === 1 ? 'UN MILLÓN' : self::entero($millones).' MILLONES') : '',
            $miles ? ($miles === 1 ? 'UN MIL' : self::menorAMil($miles).' MIL') : '',
            $resto ? self::menorAMil($resto) : '',
        ]));
    }

    private static function menorAMil(int $n): string
    {
        $unidades = ['', 'UN', 'DOS', 'TRES', 'CUATRO', 'CINCO', 'SEIS', 'SIETE', 'OCHO', 'NUEVE', 'DIEZ', 'ONCE', 'DOCE', 'TRECE', 'CATORCE', 'QUINCE', 'DIECISÉIS', 'DIECISIETE', 'DIECIOCHO', 'DIECINUEVE', 'VEINTE', 'VEINTIUN', 'VEINTIDÓS', 'VEINTITRÉS', 'VEINTICUATRO', 'VEINTICINCO', 'VEINTISÉIS', 'VEINTISIETE', 'VEINTIOCHO', 'VEINTINUEVE'];
        $decenas = ['', '', '', 'TREINTA', 'CUARENTA', 'CINCUENTA', 'SESENTA', 'SETENTA', 'OCHENTA', 'NOVENTA'];
        $centenas = ['', 'CIENTO', 'DOSCIENTOS', 'TRESCIENTOS', 'CUATROCIENTOS', 'QUINIENTOS', 'SEISCIENTOS', 'SETECIENTOS', 'OCHOCIENTOS', 'NOVECIENTOS'];
        if ($n === 100) {
            return 'CIEN';
        }
        $r = $n % 100;
        $resto = $r < 30 ? $unidades[$r] : $decenas[intdiv($r, 10)].($r % 10 ? ' Y '.$unidades[$r % 10] : '');

        return trim($centenas[intdiv($n, 100)].' '.$resto);
    }
}
