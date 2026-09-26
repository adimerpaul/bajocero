<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SiatEventoSignificativo extends Model
{
    protected $table = 'siat_eventos_significativos';

    protected $fillable = [
        'codigo_motivo', 'descripcion', 'inicio', 'fin', 'cufd', 'cufd_evento', 'codigo_evento',
        'codigo_recepcion', 'estado', 'mensaje', 'cantidad_facturas', 'user_id',
    ];

    protected function casts(): array
    {
        return ['inicio' => 'datetime', 'fin' => 'datetime'];
    }

    public function ventas()
    {
        return $this->hasMany(Venta::class, 'siat_evento_id');
    }
}
