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

/** Lotes por vencer / vencidos del último inventario aplicado. El código va como texto. */
class VencimientosExport extends DefaultValueBinder implements FromCollection, ShouldAutoSize, WithCustomValueBinder, WithHeadings, WithStrictNullComparison
{
    public function __construct(private readonly Collection $filas) {}

    public function collection(): Collection
    {
        return $this->filas->map(fn ($f) => [
            $f['codigo'],
            $f['nombre'],
            $f['categoria'],
            $f['unidad'],
            $f['lote'],
            (float) $f['cantidad'],
            date('d/m/Y', strtotime($f['fecha_vencimiento'])),
            $f['dias'],
            $f['dias'] < 0 ? 'VENCIDO HACE '.abs($f['dias']).' DÍAS' : 'VENCE EN '.$f['dias'].' DÍAS',
            $f['oculto'] ? 'QUITADO' : 'ACTIVO',
            $f['inventario'],
        ]);
    }

    public function headings(): array
    {
        return ['Código', 'Producto', 'Categoría', 'Unidad', 'Lote', 'Cantidad contada', 'Vencimiento', 'Días', 'Estado', 'En lista', 'Inventario'];
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
