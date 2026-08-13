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
 * Hoja "Ventas": una fila por venta, con autofiltro en cada columna y una fila de
 * totales con SUBTOTAL(109) para que las sumas se recalculen al filtrar.
 */
class VentasHojaVentas extends HojaReporte implements FromArray, WithEvents, WithStrictNullComparison, WithTitle
{
    private const ANCHOS = ['A' => 14, 'B' => 12, 'C' => 9, 'D' => 24, 'E' => 13, 'F' => 8, 'G' => 13, 'H' => 13, 'I' => 13, 'J' => 13, 'K' => 14, 'L' => 13];

    private int $ultimaFilaDatos = self::FILA_ENCABEZADOS;

    private int $filaTotales = self::FILA_PRIMER_DATO;

    /** Filas (1-indexadas) de ventas anuladas, para marcarlas en rojo. */
    private array $filasAnuladas = [];

    public function __construct(private readonly Collection $ventas, array $meta)
    {
        parent::__construct($meta);
    }

    public function title(): string
    {
        return 'Ventas';
    }

    protected function titulo(): string
    {
        return 'REPORTE DE VENTAS';
    }

    protected function ultimaColumna(): string
    {
        return 'L';
    }

    public function array(): array
    {
        $rows = $this->cabecera();
        $rows[] = ['Nº Venta', 'Fecha', 'Hora', 'Usuario', 'Tipo de pago', 'Ítems', 'Efectivo', 'QR', 'Subtotal', 'Descuento', 'Total', 'Estado', 'Visible (auxiliar)'];

        $fila = self::FILA_PRIMER_DATO;
        foreach ($this->ventas as $venta) {
            if ($venta->estado !== 'COMPLETADA') {
                $this->filasAnuladas[] = $fila;
            }
            $rows[] = [
                (string) $venta->numero,
                $venta->fecha ? ExcelDate::PHPToExcel($venta->fecha) : '',
                $venta->fecha ? ExcelDate::PHPToExcel($venta->fecha) : '',
                $venta->usuario_nombre,
                $venta->tipo_pago,
                (int) ($venta->detalles_count ?? 0),
                (float) $venta->monto_efectivo,
                (float) $venta->monto_qr,
                (float) $venta->subtotal,
                (float) $venta->descuento,
                (float) $venta->total,
                $venta->estado,
                // Columna auxiliar oculta: 1 si la fila está visible con el filtro actual.
                "=SUBTOTAL(103,A{$fila})",
            ];
            $fila++;
        }

        $this->ultimaFilaDatos = $this->ventas->isEmpty() ? self::FILA_ENCABEZADOS : $fila - 1;
        $this->filaTotales = $this->ultimaFilaDatos + 1;

        $rows[] = $this->ventas->isEmpty()
            ? ['Sin ventas para los filtros seleccionados']
            : $this->totales();
        $rows[] = [''];
        $rows[] = ['Los totales excluyen siempre las ventas ANULADAS (en rojo) y se recalculan según el filtro que aplique en cualquier columna.'];

        return $rows;
    }

    /**
     * Fila de totales: multiplica la bandera de visibilidad (columna M, que respeta
     * el autofiltro) por la condición de estado, así una venta anulada nunca suma
     * aunque esté a la vista.
     */
    private function totales(): array
    {
        $primera = self::FILA_PRIMER_DATO;
        $ultima = $this->ultimaFilaDatos;
        $validas = "\$M\${$primera}:\$M\${$ultima}*(\$L\${$primera}:\$L\${$ultima}=\"COMPLETADA\")";
        $suma = fn (string $columna) => "=SUMPRODUCT({$validas}*{$columna}\${$primera}:{$columna}\${$ultima})";
        $conteo = '=CONCATENATE("TOTALES (",SUMPRODUCT('.$validas.')," ventas sin anular)")';

        return [
            $conteo, '', '', '', '',
            $suma('F'), $suma('G'), $suma('H'), $suma('I'), $suma('J'), $suma('K'), '', '',
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

                $sheet->getColumnDimension('M')->setVisible(false);
                $sheet->freezePane('A'.self::FILA_PRIMER_DATO);
                $sheet->setAutoFilter('A'.self::FILA_ENCABEZADOS.':L'.max($this->ultimaFilaDatos, self::FILA_PRIMER_DATO));
                $sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd(self::FILA_ENCABEZADOS, self::FILA_ENCABEZADOS);
                $sheet->setSelectedCell('A'.self::FILA_PRIMER_DATO);
            },
        ];
    }

    private function estilarDatos(Worksheet $sheet): void
    {
        if ($this->ventas->isEmpty()) {
            $sheet->mergeCells('A'.$this->filaTotales.':L'.$this->filaTotales);
            $sheet->getStyle('A'.$this->filaTotales)->applyFromArray([
                'font' => ['italic' => true, 'color' => ['rgb' => '54657A']],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            ]);

            return;
        }

        $primera = self::FILA_PRIMER_DATO;
        $ultima = $this->ultimaFilaDatos;
        $this->bordear($sheet, "A{$primera}:L{$ultima}");
        $sheet->getStyle("A{$primera}:L{$ultima}")->getFont()->setSize(10);
        $sheet->getStyle("A{$primera}:A{$ultima}")->getFont()->setBold(true);
        $sheet->getStyle("B{$primera}:B{$ultima}")->getNumberFormat()->setFormatCode(self::FECHA);
        $sheet->getStyle("C{$primera}:C{$ultima}")->getNumberFormat()->setFormatCode(self::HORA);
        $sheet->getStyle("F{$primera}:F{$ultima}")->getNumberFormat()->setFormatCode(self::ENTERO);
        $sheet->getStyle("G{$primera}:K{$ultima}")->getNumberFormat()->setFormatCode(self::MONEDA);
        $sheet->getStyle("K{$primera}:K{$ultima}")->getFont()->setBold(true);
        $sheet->getStyle("A{$primera}:C{$ultima}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("E{$primera}:F{$ultima}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("L{$primera}:L{$ultima}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        for ($fila = $primera; $fila <= $ultima; $fila++) {
            if ($fila % 2 === 0) {
                $sheet->getStyle("A{$fila}:L{$fila}")->getFill()
                    ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::GRIS_FILA);
            }
        }
        foreach ($this->filasAnuladas as $fila) {
            $sheet->getStyle("A{$fila}:L{$fila}")->getFont()->getColor()->setRGB(self::ROJO);
            $sheet->getStyle("L{$fila}")->getFont()->setBold(true);
        }
    }

    private function estilarTotales(Worksheet $sheet): void
    {
        if ($this->ventas->isEmpty()) {
            return;
        }
        $fila = $this->filaTotales;
        $sheet->mergeCells("A{$fila}:E{$fila}");
        $sheet->getStyle("A{$fila}:L{$fila}")->applyFromArray([
            'font' => ['bold' => true, 'size' => 10, 'color' => ['rgb' => self::AZUL]],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::AZUL_SUAVE]],
            'borders' => [
                'top' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['rgb' => self::AZUL_MEDIO]],
                'bottom' => ['borderStyle' => Border::BORDER_DOUBLE, 'color' => ['rgb' => self::AZUL_MEDIO]],
            ],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getStyle("A{$fila}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $sheet->getStyle("F{$fila}")->getNumberFormat()->setFormatCode(self::ENTERO);
        $sheet->getStyle("G{$fila}:K{$fila}")->getNumberFormat()->setFormatCode(self::MONEDA);
        $sheet->getStyle("F{$fila}:F{$fila}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("L{$fila}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getRowDimension($fila)->setRowHeight(20);

        $nota = $fila + 2;
        $sheet->mergeCells("A{$nota}:L{$nota}");
        $sheet->getStyle("A{$nota}")->applyFromArray([
            'font' => ['italic' => true, 'size' => 8, 'color' => ['rgb' => '78889C']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT],
        ]);
    }
}
