<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

class Venta extends Model implements AuditableContract
{
    use Auditable, SoftDeletes;

    protected $fillable = [
        'numero', 'uuid', 'user_id', 'usuario_nombre', 'subtotal', 'descuento',
        'total', 'tipo_pago', 'monto_efectivo', 'monto_qr',
        'estado', 'observacion', 'fecha', 'fecha_offline', 'cliente_id', 'tipo_comprobante',
        'numero_factura', 'tipo_documento', 'numero_documento', 'complemento', 'cliente_nombre',
        'cliente_email', 'codigo_excepcion', 'estado_siat', 'tipo_emision', 'cuf', 'cufd',
        'codigo_recepcion', 'leyenda', 'xml_path', 'siat_mensaje', 'fecha_emision_siat', 'siat_evento_id',
        'email_enviado_en', 'email_error',
    ];

    protected $casts = [
        'subtotal' => 'decimal:2', 'descuento' => 'decimal:2',
        'total' => 'decimal:2', 'monto_efectivo' => 'decimal:2',
        'monto_qr' => 'decimal:2', 'fecha' => 'datetime',
        'fecha_offline' => 'datetime', 'fecha_emision_siat' => 'datetime', 'email_enviado_en' => 'datetime',
    ];

    protected $appends = ['factura_url'];

    /** Enlace de consulta pública del SIN que va en el QR de la factura (rollo: t=2). */
    public function getFacturaUrlAttribute(): ?string
    {
        if ($this->tipo_comprobante !== 'FACTURA' || ! $this->cuf) {
            return null;
        }

        return config('siat.qr_url').'?'.http_build_query([
            'nit' => config('siat.nit'), 'cuf' => $this->cuf, 'numero' => $this->numero_factura, 't' => 2,
        ]);
    }

    public function detalles()
    {
        return $this->hasMany(VentaDetalle::class);
    }

    public function usuario()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function cliente()
    {
        return $this->belongsTo(Cliente::class);
    }

    public function evento()
    {
        return $this->belongsTo(SiatEventoSignificativo::class, 'siat_evento_id');
    }
}
