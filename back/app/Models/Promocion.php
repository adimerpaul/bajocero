<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

class Promocion extends Model implements AuditableContract
{
    use Auditable, SoftDeletes;

    protected $table = 'promociones';

    protected $fillable = ['nombre', 'titulo', 'subtitulo', 'monto', 'valor_ficha', 'impresiones', 'estado'];

    protected $casts = [
        'monto' => 'decimal:2',
        'valor_ficha' => 'decimal:2',
        'impresiones' => 'integer',
        'estado' => 'boolean',
    ];
}
