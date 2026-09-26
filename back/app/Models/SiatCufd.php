<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SiatCufd extends Model
{
    protected $fillable = ['codigo', 'codigo_control', 'direccion', 'vence_en', 'sucursal', 'punto_venta'];

    protected function casts(): array
    {
        return ['vence_en' => 'datetime'];
    }
}
