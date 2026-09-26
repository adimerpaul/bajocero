<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SiatCatalogo extends Model
{
    protected $fillable = ['tipo', 'codigo', 'codigo_actividad', 'descripcion'];
}
