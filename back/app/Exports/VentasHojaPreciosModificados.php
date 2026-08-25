<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

class VentasHojaPreciosModificados extends HojaReporte implements FromArray, WithEvents, WithStrictNullComparison, WithTitle
{
    private int $ultimaFila = self::FILA_ENCABEZADOS;

    public function __construct(private readonly Collection $ventas, array $meta) { parent::__construct($meta); }
    public function title(): string { return 'Precios modificados'; }
    protected function titulo(): string { return 'PRECIOS MODIFICADOS EN VENTAS'; }
    protected function ultimaColumna(): string { return 'L'; }

    public function array(): array
    {
        $rows = $this->cabecera();
        $rows[] = ['Nº Venta', 'Fecha', 'Código', 'Producto', 'Cantidad', 'Precio base', 'Precio aplicado', 'Diferencia', 'Impacto total', 'Usuario', 'Estado', 'Tipo de cambio'];
        foreach ($this->ventas as $venta) {
            foreach ($venta->detalles->where('precio_cambiado', true) as $detalle) {
                $diferencia = (float) $detalle->precio_venta - (float) $detalle->precio_base;
                $rows[] = [$venta->numero, $venta->fecha ? ExcelDate::PHPToExcel($venta->fecha) : '', $detalle->codigo,
                    $detalle->nombre, (float) $detalle->cantidad, (float) $detalle->precio_base,
                    (float) $detalle->precio_venta, $diferencia, $diferencia * (float) $detalle->cantidad,
                    $venta->usuario_nombre, $venta->estado, $diferencia < 0 ? 'Rebajado' : 'Aumentado'];
            }
        }
        if (count($rows) === self::FILA_ENCABEZADOS) $rows[] = ['No existen precios modificados para los filtros seleccionados'];
        $this->ultimaFila = count($rows);
        return $rows;
    }

    public function registerEvents(): array
    {
        return [AfterSheet::class => function (AfterSheet $event) {
            $sheet = $event->sheet->getDelegate();
            $this->estilarCabecera($sheet); $this->estilarEncabezados($sheet);
            $this->anchos($sheet, ['A'=>14,'B'=>17,'C'=>14,'D'=>36,'E'=>11,'F'=>14,'G'=>15,'H'=>13,'I'=>15,'J'=>22,'K'=>14,'L'=>18]);
            if ($this->ultimaFila >= self::FILA_PRIMER_DATO) {
                $this->bordear($sheet, 'A'.self::FILA_PRIMER_DATO.':L'.$this->ultimaFila);
                $sheet->getStyle('B'.self::FILA_PRIMER_DATO.':B'.$this->ultimaFila)->getNumberFormat()->setFormatCode('dd/mm/yyyy hh:mm');
                $sheet->getStyle('E'.self::FILA_PRIMER_DATO.':E'.$this->ultimaFila)->getNumberFormat()->setFormatCode(self::CANTIDAD);
                $sheet->getStyle('F'.self::FILA_PRIMER_DATO.':I'.$this->ultimaFila)->getNumberFormat()->setFormatCode(self::MONEDA);
            }
            $sheet->freezePane('A'.self::FILA_PRIMER_DATO);
            $sheet->setAutoFilter('A'.self::FILA_ENCABEZADOS.':L'.max($this->ultimaFila, self::FILA_PRIMER_DATO));
            $this->prepararImpresion($sheet);
        }];
    }
}
