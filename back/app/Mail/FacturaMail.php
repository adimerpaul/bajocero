<?php

namespace App\Mail;

use App\Models\Venta;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Factura al cliente: PDF armado en memoria (no se guarda) + XML. Con $anulada es el aviso de anulación. */
class FacturaMail extends Mailable
{
    public function __construct(
        public Venta $sale,
        public string $empresa,
        private string $pdf,
        private ?string $xml,
        public bool $anulada = false,
        public ?string $motivo = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "{$this->empresa} - Factura N° {$this->sale->numero_factura}".($this->anulada ? ' ANULADA' : ''));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.factura');
    }

    public function attachments(): array
    {
        $name = "Factura_{$this->sale->numero_factura}".($this->anulada ? '_ANULADA' : '');
        $files = [Attachment::fromData(fn () => $this->pdf, "{$name}.pdf")->withMime('application/pdf')];
        if ($this->xml) {
            $files[] = Attachment::fromData(fn () => $this->xml, "{$name}.xml")->withMime('application/xml');
        }

        return $files;
    }
}
