<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/** Libro de ventas: hojas filtrables de ventas y de productos vendidos + resumen. */
class VentasExport implements WithMultipleSheets
{
    public function __construct(
        private readonly Collection $ventas,
        private readonly Collection $productos,
        private readonly array $meta,
    ) {}

    public function sheets(): array
    {
        return [
            new VentasHojaVentas($this->ventas, $this->meta),
            new VentasHojaProductos($this->ventas, $this->meta),
            new VentasHojaPreciosModificados($this->ventas, $this->meta),
            new VentasHojaResumen($this->ventas, $this->productos, $this->meta),
        ];
    }
}
