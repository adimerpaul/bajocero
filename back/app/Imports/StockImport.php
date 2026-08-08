<?php

namespace App\Imports;

use Maatwebsite\Excel\Concerns\ToArray;

/** Lector plano del modelo de cantidades; el controlador interpreta las filas. */
class StockImport implements ToArray
{
    public function array(array $array): void {}
}
