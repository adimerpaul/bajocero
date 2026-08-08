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

/** Código y código de barras van como texto: de lo contrario Excel los convierte a notación científica. */
class ProductosExport extends DefaultValueBinder implements FromCollection, ShouldAutoSize, WithCustomValueBinder, WithHeadings, WithStrictNullComparison
{
    public function __construct(private readonly Collection $productos) {}

    public function collection(): Collection
    {
        return $this->productos->map(fn ($p) => [
            $p->codigo,
            $p->codigo_barras,
            $p->nombre,
            $p->categoriaRelacion?->nombre ?? $p->categoria,
            $p->unidad,
            (float) $p->precio_compra,
            (float) $p->precio_venta,
            (float) $p->stock_inicial,
            round((float) $p->stock_inicial * (float) $p->precio_compra, 2),
            round((float) $p->stock_inicial * (float) $p->precio_venta, 2),
        ]);
    }

    public function headings(): array
    {
        return ['Código', 'Código barras', 'Producto', 'Categoría', 'Unidad', 'P. compra', 'P. venta', 'Stock', 'Valor compra', 'Valor venta'];
    }

    public function bindValue(Cell $cell, $value): bool
    {
        if (in_array($cell->getColumn(), ['A', 'B'], true)) {
            $cell->setValueExplicit((string) $value, DataType::TYPE_STRING);

            return true;
        }

        return parent::bindValue($cell, $value);
    }
}
