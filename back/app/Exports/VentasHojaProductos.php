<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Hoja "Productos": una fila por producto vendido (línea de venta), incluidas las
 * de ventas anuladas para poder revisarlas. Al filtrar por producto, categoría o
 * usuario, la fila de totales muestra las cantidades e importes de esa selección
 * sin contar las anuladas.
 */
class VentasHojaProductos extends HojaReporte implements FromArray, WithEvents, WithStrictNullComparison, WithTitle
{
    private const ANCHOS = ['A' => 14, 'B' => 12, 'C' => 13, 'D' => 40, 'E' => 20, 'F' => 9, 'G' => 11, 'H' => 13, 'I' => 12, 'J' => 14, 'K' => 20, 'L' => 13, 'M' => 13];

    private const MONEDA_UNITARIA = '"Bs" #,##0.00##';

    private int $ultimaFilaDatos = self::FILA_ENCABEZADOS;

    private int $filaTotales = self::FILA_PRIMER_DATO;

    private int $lineas = 0;

    /** Filas de líneas pertenecientes a ventas anuladas, para marcarlas en rojo. */
    private array $filasAnuladas = [];

    public function __construct(private readonly Collection $ventas, array $meta)
    {
        parent::__construct($meta);
    }

    public function title(): string
    {
        return 'Productos';
    }

    protected function titulo(): string
    {
        return 'PRODUCTOS VENDIDOS (DETALLE POR LÍNEA)';
    }

    protected function ultimaColumna(): string
    {
        return 'M';
    }

    public function array(): array
    {
        $rows = $this->cabecera();
        $rows[] = ['Nº Venta', 'Fecha', 'Código', 'Producto', 'Categoría', 'Unidad', 'Cantidad', 'P. unitario', 'Descuento', 'Total', 'Usuario', 'Tipo de pago', 'Estado', 'Visible (auxiliar)'];

        $fila = self::FILA_PRIMER_DATO;
        foreach ($this->ventas as $venta) {
            foreach ($venta->detalles as $detalle) {
                if ($venta->estado !== 'COMPLETADA') {
                    $this->filasAnuladas[] = $fila;
                }
                $rows[] = [
                    (string) $venta->numero,
                    $venta->fecha ? ExcelDate::PHPToExcel($venta->fecha) : '',
                    (string) $detalle->codigo,
                    $detalle->nombre,
                    $detalle->categoria,
                    $detalle->unidad,
                    (float) $detalle->cantidad,
                    (float) $detalle->precio_venta,
                    (float) $detalle->descuento,
                    (float) $detalle->total,
                    $venta->usuario_nombre,
                    $venta->tipo_pago,
                    $venta->estado,
                    "=SUBTOTAL(103,A{$fila})",
                ];
                $fila++;
            }
        }

        $this->lineas = $fila - self::FILA_PRIMER_DATO;
        $this->ultimaFilaDatos = $this->lineas ? $fila - 1 : self::FILA_ENCABEZADOS;
        $this->filaTotales = $this->ultimaFilaDatos + 1;

        $rows[] = $this->lineas
            ? $this->totales()
            : ['Sin productos vendidos para los filtros seleccionados'];
        $rows[] = [''];
        $rows[] = ['Filtre por producto, categoría o usuario para ver la cantidad y el importe de esa selección. Los totales excluyen las líneas de ventas ANULADAS (en rojo); la suma de cantidades sólo es comparable dentro de una misma unidad.'];

        return $rows;
    }

    /** Mismo criterio que la hoja de ventas: visible con el filtro y no anulada. */
    private function totales(): array
    {
        $primera = self::FILA_PRIMER_DATO;
        $ultima = $this->ultimaFilaDatos;
        $validas = "\$N\${$primera}:\$N\${$ultima}*(\$M\${$primera}:\$M\${$ultima}=\"COMPLETADA\")";
        $suma = fn (string $columna) => "=SUMPRODUCT({$validas}*{$columna}\${$primera}:{$columna}\${$ultima})";

        return [
            '=CONCATENATE("TOTALES (",SUMPRODUCT('.$validas.')," líneas sin anular)")',
            '', '', '', '', '',
            $suma('G'), '', $suma('I'), $suma('J'), '', '', '', '',
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $this->estilarCabecera($sheet);
                $this->estilarEncabezados($sheet);
                $this->anchos($sheet, self::ANCHOS);
                $this->estilarDatos($sheet);
                $this->estilarTotales($sheet);
                $this->prepararImpresion($sheet);

                $sheet->getColumnDimension('N')->setVisible(false);
                $sheet->freezePane('A'.self::FILA_PRIMER_DATO);
                $sheet->setAutoFilter('A'.self::FILA_ENCABEZADOS.':M'.max($this->ultimaFilaDatos, self::FILA_PRIMER_DATO));
                $sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd(self::FILA_ENCABEZADOS, self::FILA_ENCABEZADOS);
                $sheet->setSelectedCell('A'.self::FILA_PRIMER_DATO);
            },
        ];
    }

    private function estilarDatos(Worksheet $sheet): void
    {
        if (! $this->lineas) {
            $sheet->mergeCells('A'.$this->filaTotales.':M'.$this->filaTotales);
            $sheet->getStyle('A'.$this->filaTotales)->applyFromArray([
                'font' => ['italic' => true, 'color' => ['rgb' => '54657A']],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            ]);

            return;
        }

        $primera = self::FILA_PRIMER_DATO;
        $ultima = $this->ultimaFilaDatos;
        $this->bordear($sheet, "A{$primera}:M{$ultima}");
        $sheet->getStyle("A{$primera}:M{$ultima}")->getFont()->setSize(10);
        $sheet->getStyle("B{$primera}:B{$ultima}")->getNumberFormat()->setFormatCode(self::FECHA);
        $sheet->getStyle("G{$primera}:G{$ultima}")->getNumberFormat()->setFormatCode(self::CANTIDAD);
        $sheet->getStyle("H{$primera}:H{$ultima}")->getNumberFormat()->setFormatCode(self::MONEDA_UNITARIA);
        $sheet->getStyle("I{$primera}:J{$ultima}")->getNumberFormat()->setFormatCode(self::MONEDA);
        $sheet->getStyle("J{$primera}:J{$ultima}")->getFont()->setBold(true);
        $sheet->getStyle("A{$primera}:C{$ultima}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("F{$primera}:F{$ultima}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("L{$primera}:M{$ultima}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        for ($fila = $primera; $fila <= $ultima; $fila++) {
            if ($fila % 2 === 0) {
                $sheet->getStyle("A{$fila}:M{$fila}")->getFill()
                    ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::GRIS_FILA);
            }
        }
        foreach ($this->filasAnuladas as $fila) {
            $sheet->getStyle("A{$fila}:M{$fila}")->getFont()->getColor()->setRGB(self::ROJO);
            $sheet->getStyle("M{$fila}")->getFont()->setBold(true);
        }
    }

    private function estilarTotales(Worksheet $sheet): void
    {
        if (! $this->lineas) {
            return;
        }
        $fila = $this->filaTotales;
        $sheet->mergeCells("A{$fila}:F{$fila}");
        $sheet->getStyle("A{$fila}:M{$fila}")->applyFromArray([
            'font' => ['bold' => true, 'size' => 10, 'color' => ['rgb' => self::AZUL]],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::AZUL_SUAVE]],
            'borders' => [
                'top' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['rgb' => self::AZUL_MEDIO]],
                'bottom' => ['borderStyle' => Border::BORDER_DOUBLE, 'color' => ['rgb' => self::AZUL_MEDIO]],
            ],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getStyle("A{$fila}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $sheet->getStyle("G{$fila}")->getNumberFormat()->setFormatCode(self::CANTIDAD);
        $sheet->getStyle("I{$fila}:J{$fila}")->getNumberFormat()->setFormatCode(self::MONEDA);
        $sheet->getRowDimension($fila)->setRowHeight(20);

        $nota = $fila + 2;
        $sheet->mergeCells("A{$nota}:M{$nota}");
        $sheet->getStyle("A{$nota}")->applyFromArray([
            'font' => ['italic' => true, 'size' => 8, 'color' => ['rgb' => '78889C']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT],
        ]);
    }
}
