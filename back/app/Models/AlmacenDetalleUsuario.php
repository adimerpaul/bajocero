<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Lo que contó un usuario de un producto dentro de una revisión; el producto suma todos. */
class AlmacenDetalleUsuario extends Model
{
    protected $table = 'almacen_detalle_usuarios';

    protected $fillable = ['almacen_detalle_id', 'user_id', 'usuario_nombre', 'cantidad', 'observacion'];

    protected $casts = [
        'cantidad' => 'decimal:3',
    ];

    public function detalle()
    {
        return $this->belongsTo(AlmacenDetalle::class, 'almacen_detalle_id');
    }

    public function conteos()
    {
        return $this->hasMany(AlmacenDetalleConteo::class, 'almacen_detalle_usuario_id');
    }
}
