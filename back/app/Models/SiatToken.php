<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SiatToken extends Model
{
    use SoftDeletes;

    protected $fillable = ['token_cifrado', 'vence_en'];

    protected $hidden = ['token_cifrado'];

    protected function casts(): array
    {
        return ['token_cifrado' => 'encrypted', 'vence_en' => 'datetime'];
    }
}
