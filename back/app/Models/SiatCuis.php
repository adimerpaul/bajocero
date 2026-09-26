<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SiatCuis extends Model
{
    protected $table = 'siat_cuis';

    protected $fillable = ['codigo', 'vence_en', 'sucursal', 'punto_venta'];

    protected function casts(): array
    {
        return ['vence_en' => 'datetime'];
    }
}
