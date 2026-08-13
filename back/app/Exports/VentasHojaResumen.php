<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Hoja "Resumen": indicadores del período y cortes por tipo de pago, usuario y
 * producto. Los importes excluyen las ventas anuladas (salvo el corte de anuladas).
 */
class VentasHojaResumen extends HojaReporte implements FromArray, WithEvents, WithStrictNullComparison, WithTitle
{
    private const ANCHOS = ['A' => 40, 'B' => 14, 'C' => 16, 'D' => 12];

    /** @var array<int,array{0:string,1:string}> rangos por formato: [formato, rango] */
    private array $formatos = [];

    private array $filasTitulo = [];

    private array $filasEncabezado = [];

    private array $filasTotal = [];

    private array $rangosBorde = [];

    private int $fila = self::FILA_ENCABEZADOS;

    public function __construct(
        private readonly Collection $ventas,
        private readonly Collection $productos,
        array $meta,
    ) {
        parent::__construct($meta);
    }

    public function title(): string
    {
        return 'Resumen';
    }

    protected function titulo(): string
    {
        return 'RESUMEN DE VENTAS';
    }

    protected function ultimaColumna(): string
    {
        return 'D';
    }

    public function array(): array
    {
        $validas = $this->ventas->where('estado', 'COMPLETADA');
        $anuladas = $this->ventas->where('estado', '!=', 'COMPLETADA');
        $total = (float) $validas->sum('total');

        $rows = $this->cabecera();
        $this->fila = count($rows);

        $rows = array_merge($rows, $this->indicadores($validas, $anuladas, $total));
        $rows[] = [''];
        $this->fila++;
        $rows = array_merge($rows, $this->corte('VENTAS POR TIPO DE PAGO', 'Tipo de pago',
            $validas->groupBy('tipo_pago')->map(fn ($g, $k) => [$k, $g->count(), (float) $g->sum('total')])->sortByDesc(fn ($r) => $r[2])->values()->all(), $total));
        $rows[] = [''];
        $this->fila++;
        $rows = array_merge($rows, $this->corte('VENTAS POR USUARIO', 'Usuario',
            $validas->groupBy('usuario_nombre')->map(fn ($g, $k) => [$k, $g->count(), (float) $g->sum('total')])->sortByDesc(fn ($r) => $r[2])->values()->all(), $total));
        $rows[] = [''];
        $this->fila++;
        $rows = array_merge($rows, $this->topProductos());

        return $rows;
    }

    private function indicadores(Collection $validas, Collection $anuladas, float $total): array
    {
        $cantidad = $validas->count();
        $filas = [
            ['Ventas completadas', $cantidad, self::ENTERO],
            ['Total vendido', $total, self::MONEDA],
            ['Cobrado en efectivo', (float) $validas->sum('monto_efectivo'), self::MONEDA],
            ['Cobrado por QR', (float) $validas->sum('monto_qr'), self::MONEDA],
            ['Descuentos otorgados', (float) $validas->sum('descuento'), self::MONEDA],
            ['Ticket promedio', $cantidad ? $total / $cantidad : 0, self::MONEDA],
            ['Ventas anuladas', $anuladas->count(), self::ENTERO],
            ['Monto anulado', (float) $anuladas->sum('total'), self::MONEDA],
        ];

        $rows = [['INDICADORES DEL PERÍODO']];
        $this->filasTitulo[] = ++$this->fila;
        foreach ($filas as [$etiqueta, $valor, $formato]) {
            $rows[] = [$etiqueta, $valor];
            $this->fila++;
            $this->formatos[] = [$formato, 'B'.$this->fila];
        }
        $this->rangosBorde[] = 'A'.($this->fila - count($filas) + 1).':B'.$this->fila;

        return $rows;
    }

    /** Bloque con encabezados, filas [etiqueta, cantidad, total] y su fila de total. */
    private function corte(string $titulo, string $etiqueta, array $filas, float $total): array
    {
        $rows = [[$titulo], [$etiqueta, 'Ventas', 'Total', '% del total']];
        $this->filasTitulo[] = ++$this->fila;
        $this->filasEncabezado[] = ++$this->fila;

        $primera = $this->fila + 1;
        foreach ($filas as [$nombre, $ventas, $monto]) {
            $rows[] = [$nombre, $ventas, $monto, $total > 0 ? $monto / $total : 0];
            $this->fila++;
        }
        if (! $filas) {
            $rows[] = ['Sin datos', 0, 0.0, 0];
            $this->fila++;
        }
        $rows[] = ['TOTAL', array_sum(array_column($filas, 1)), (float) array_sum(array_column($filas, 2)), $total > 0 ? 1 : 0];
        $this->fila++;

        $this->filasTotal[] = $this->fila;
        $this->rangosBorde[] = "A{$primera}:D".$this->fila;
        $this->formatos[] = [self::ENTERO, "B{$primera}:B".$this->fila];
        $this->formatos[] = [self::MONEDA, "C{$primera}:C".$this->fila];
        $this->formatos[] = [self::PORCENTAJE, "D{$primera}:D".$this->fila];

        return $rows;
    }

    private function topProductos(): array
    {
        $rows = [['PRODUCTOS MÁS VENDIDOS'], ['Producto', 'Unidad', 'Cantidad', 'Total']];
        $this->filasTitulo[] = ++$this->fila;
        $this->filasEncabezado[] = ++$this->fila;

        $primera = $this->fila + 1;
        foreach ($this->productos as $producto) {
            $rows[] = [$producto->nombre, $producto->unidad, (float) $producto->cantidad, (float) $producto->total];
            $this->fila++;
        }
        if ($this->productos->isEmpty()) {
            $rows[] = ['Sin datos', '', 0.0, 0.0];
            $this->fila++;
        }
        $rows[] = ['TOTAL', '', (float) $this->productos->sum('cantidad'), (float) $this->productos->sum('total')];
        $this->fila++;

        $this->filasTotal[] = $this->fila;
        $this->rangosBorde[] = "A{$primera}:D".$this->fila;
        $this->formatos[] = [self::CANTIDAD, "C{$primera}:C".$this->fila];
        $this->formatos[] = [self::MONEDA, "D{$primera}:D".$this->fila];

        return $rows;
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $this->estilarCabecera($sheet);
                $this->anchos($sheet, self::ANCHOS);

                foreach ($this->filasTitulo as $fila) {
                    $sheet->mergeCells("A{$fila}:D{$fila}");
                    $sheet->getStyle("A{$fila}:D{$fila}")->applyFromArray([
                        'font' => ['bold' => true, 'size' => 10, 'color' => ['rgb' => 'FFFFFF']],
                        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::AZUL]],
                        'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER, 'indent' => 1],
                    ]);
                    $sheet->getRowDimension($fila)->setRowHeight(19);
                }
                foreach ($this->filasEncabezado as $fila) {
                    $this->estilarEncabezados($sheet, $fila);
                }
                foreach ($this->rangosBorde as $rango) {
                    $this->bordear($sheet, $rango);
                    $sheet->getStyle($rango)->getFont()->setSize(10);
                }
                foreach ($this->formatos as [$formato, $rango]) {
                    $sheet->getStyle($rango)->getNumberFormat()->setFormatCode($formato);
                }
                foreach ($this->filasTotal as $fila) {
                    $sheet->getStyle("A{$fila}:D{$fila}")->applyFromArray([
                        'font' => ['bold' => true, 'color' => ['rgb' => self::AZUL]],
                        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::AZUL_SUAVE]],
                        'borders' => ['top' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['rgb' => self::AZUL_MEDIO]]],
                    ]);
                }
                $this->estilarIndicadores($sheet);
                $this->prepararImpresion($sheet);
                $sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_PORTRAIT);
            },
        ];
    }

    private function estilarIndicadores(Worksheet $sheet): void
    {
        $primera = self::FILA_PRIMER_DATO;
        $ultima = $primera + 7;
        $sheet->getStyle("A{$primera}:A{$ultima}")->getFont()->setBold(true);
        $sheet->getStyle("B{$primera}:B{$ultima}")->applyFromArray([
            'font' => ['bold' => true, 'size' => 11, 'color' => ['rgb' => self::AZUL_MEDIO]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT],
        ]);
        $sheet->getStyle('A'.($primera + 6).':B'.$ultima)->getFont()->getColor()->setRGB(self::ROJO);
    }
}
