<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

class Cliente extends Model implements AuditableContract
{
    use Auditable, SoftDeletes;

    /** Tipo de documento de identidad → código paramétrico del SIN. */
    public const TIPOS_DOCUMENTO = ['CI' => 1, 'CEX' => 2, 'PAS' => 3, 'OD' => 4, 'NIT' => 5];

    /** Comprador que no da sus datos: código especial del SIN y la razón social que exige. */
    public const DOCUMENTO_SIN_DATOS = '99002';

    public const NOMBRE_SIN_DATOS = 'CONTROL TRIBUTARIO';

    protected $fillable = [
        'tipo_documento', 'numero_documento', 'complemento', 'nombre', 'email', 'telefono', 'direccion',
    ];

    public function ventas()
    {
        return $this->hasMany(Venta::class);
    }
}
