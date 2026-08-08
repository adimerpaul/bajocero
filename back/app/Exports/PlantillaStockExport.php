<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;

/**
 * Modelo para el llenado rápido de cantidades.
 *
 * Columnas: Código | Producto | Cantidad | Cantidad anotada | Diferencia.
 * Se llena "Cantidad anotada" (lo contado) y esa es la que se importa; "Diferencia" es una
 * fórmula de Excel que se calcula sola y solo sirve de ayuda visual.
 *
 * WithStrictNullComparison: sin él un stock 0 se escribiría como celda vacía y el importador la saltaría.
 * El binder fuerza la columna A a texto: los códigos largos (más de 15 dígitos) se corrompen
 * irreversiblemente si Excel los guarda como número.
 */
class PlantillaStockExport extends DefaultValueBinder implements FromCollection, ShouldAutoSize, WithCustomValueBinder, WithHeadings, WithStrictNullComparison
{
    public function __construct(private readonly Collection $productos) {}

    public function collection(): Collection
    {
        $row = 1; // la fila 1 es el encabezado

        return $this->productos->map(function ($p) use (&$row) {
            $row++;

            return [
                (string) $p->codigo,
                $p->nombre,
                (float) $p->stock_inicial,
                null,
                "=IF(D{$row}=\"\",\"\",D{$row}-C{$row})",
            ];
        });
    }

    public function headings(): array
    {
        return ['Código', 'Producto', 'Cantidad', 'Cantidad anotada', 'Diferencia'];
    }

    public function bindValue(Cell $cell, $value): bool
    {
        if ($cell->getColumn() === 'A') {
            $cell->setValueExplicit((string) $value, DataType::TYPE_STRING);

            return true;
        }

        return parent::bindValue($cell, $value);
    }
}
