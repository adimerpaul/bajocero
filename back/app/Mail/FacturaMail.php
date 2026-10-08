<?php

namespace App\Mail;

use App\Models\Venta;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Factura al cliente: PDF armado en memoria (no se guarda) + XML. Con $anulada es aviso de anulación, con $revertida es aviso de reversión. */
class FacturaMail extends Mailable
{
    public function __construct(
        public Venta $sale,
        public string $empresa,
        private string $pdf,
        private ?string $xml,
        public bool $anulada = false,
        public ?string $motivo = null,
        public bool $revertida = false,
    ) {}

    public function envelope(): Envelope
    {
        $estado = '';
        if ($this->revertida) {
            $estado = ' - ANULACIÓN REVERTIDA';
        } elseif ($this->anulada) {
            $estado = ' ANULADA';
        }

        return new Envelope(subject: "{$this->empresa} - Factura N° {$this->sale->numero_factura}{$estado}");
    }

    public function content(): Content
    {
        return new Content(view: 'emails.factura');
    }

    public function attachments(): array
    {
        $suffix = '';
        if ($this->revertida) {
            $suffix = '_REVERTIDA';
        } elseif ($this->anulada) {
            $suffix = '_ANULADA';
        }
        $name = "Factura_{$this->sale->numero_factura}{$suffix}";
        $files = [Attachment::fromData(fn () => $this->pdf, "{$name}.pdf")->withMime('application/pdf')];
        if ($this->xml) {
            $files[] = Attachment::fromData(fn () => $this->xml, "{$name}.xml")->withMime('application/xml');
        }

        return $files;
    }
}
