<?php

namespace App\Exports;

use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Base de las hojas de reporte: pinta el encabezado corporativo (empresa, período,
 * filtros aplicados y quién exportó) y expone la paleta/formatos comunes.
 *
 * Distribución fija de filas: 1 empresa, 2 datos empresa, 3 título, 4 período,
 * 5 filtros, 6 exportado por, 7 separador, 8 encabezados, 9 primer dato.
 */
abstract class HojaReporte
{
    protected const FILA_ENCABEZADOS = 8;

    protected const FILA_PRIMER_DATO = 9;

    protected const MONEDA = '"Bs" #,##0.00';

    protected const CANTIDAD = '#,##0.###';

    protected const ENTERO = '#,##0';

    protected const PORCENTAJE = '0.0%';

    protected const FECHA = 'dd/mm/yyyy';

    protected const HORA = 'hh:mm';

    protected const AZUL = '0D3B66';

    protected const AZUL_MEDIO = '1565C0';

    protected const AZUL_SUAVE = 'E8F1FB';

    protected const GRIS_FILA = 'F7F9FC';

    protected const GRIS_LINEA = 'D6DEE8';

    protected const ROJO = 'C62828';

    public function __construct(protected readonly array $meta) {}

    abstract protected function titulo(): string;

    abstract protected function ultimaColumna(): string;

    /** Las 7 filas previas a los encabezados de columna. */
    protected function cabecera(): array
    {
        $empresa = $this->meta['empresa'] ?? 'BAJO CERO';
        $datos = array_filter([
            ($this->meta['nit'] ?? null) ? 'NIT '.$this->meta['nit'] : null,
            $this->meta['direccion'] ?? null,
            ($this->meta['telefono'] ?? null) ? 'Tel. '.$this->meta['telefono'] : null,
        ]);

        return [
            [mb_strtoupper($empresa)],
            [$datos ? implode('  ·  ', $datos) : ''],
            [$this->titulo()],
            ['Período: '.($this->meta['periodo'] ?? 'Todos los registros')],
            ['Filtros: '.($this->meta['filtros'] ?? 'Sin filtros adicionales')],
            ['Exportado por: '.($this->meta['exportado_por'] ?? '-').'   ·   '.($this->meta['exportado_en'] ?? '')],
            [''],
        ];
    }

    protected function estilarCabecera(Worksheet $sheet): void
    {
        $ultima = $this->ultimaColumna();
        foreach (range(1, 7) as $fila) {
            $sheet->mergeCells("A{$fila}:{$ultima}{$fila}");
        }

        $sheet->getStyle("A1:{$ultima}1")->applyFromArray([
            'font' => ['bold' => true, 'size' => 15, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::AZUL]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getStyle("A2:{$ultima}2")->applyFromArray([
            'font' => ['size' => 9, 'color' => ['rgb' => 'C9DCF0']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::AZUL]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);
        $sheet->getStyle("A3:{$ultima}3")->applyFromArray([
            'font' => ['bold' => true, 'size' => 11, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::AZUL_MEDIO]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getStyle("A4:{$ultima}6")->applyFromArray([
            'font' => ['size' => 9, 'italic' => true, 'color' => ['rgb' => '54657A']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::AZUL_SUAVE]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'indent' => 1],
        ]);

        $sheet->getRowDimension(1)->setRowHeight(26);
        $sheet->getRowDimension(3)->setRowHeight(20);
        $sheet->getRowDimension(7)->setRowHeight(6);
    }

    protected function estilarEncabezados(Worksheet $sheet, int $fila = self::FILA_ENCABEZADOS): void
    {
        $ultima = $this->ultimaColumna();
        $sheet->getStyle("A{$fila}:{$ultima}{$fila}")->applyFromArray([
            'font' => ['bold' => true, 'size' => 10, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::AZUL_MEDIO]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'FFFFFF']]],
        ]);
        $sheet->getRowDimension($fila)->setRowHeight(22);
    }

    protected function bordear(Worksheet $sheet, string $rango): void
    {
        $sheet->getStyle($rango)->applyFromArray([
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => self::GRIS_LINEA]]],
        ]);
    }

    protected function anchos(Worksheet $sheet, array $anchos): void
    {
        foreach ($anchos as $columna => $ancho) {
            $sheet->getColumnDimension($columna)->setWidth($ancho);
        }
    }

    protected function prepararImpresion(Worksheet $sheet): void
    {
        $sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE);
        $sheet->getPageSetup()->setFitToWidth(1);
        $sheet->getPageSetup()->setFitToHeight(0);
        $sheet->getPageSetup()->setPrintArea('A1:'.$this->ultimaColumna().$sheet->getHighestRow());
        $sheet->getPageMargins()->setTop(0.4)->setBottom(0.4)->setLeft(0.3)->setRight(0.3);
        $sheet->getHeaderFooter()->setOddFooter('&L&9'.($this->meta['empresa'] ?? '').'&R&9Página &P de &N');
    }
}
