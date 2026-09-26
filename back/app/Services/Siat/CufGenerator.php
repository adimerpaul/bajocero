<?php

namespace App\Services\Siat;

/**
 * Código Único de Factura (algoritmo publicado por el SIN): NIT, fecha con
 * milisegundos, sucursal, modalidad, tipo de emisión, tipo de factura, documento
 * sector, número y punto de venta → dígito módulo 11 → base 16 → + código de control
 * del CUFD.
 */
class CufGenerator
{
    public function generate(string $nit, string $timestamp, int $sucursal, int $modalidad, int $emision, int $numeroFactura, int $puntoVenta, string $codigoControl): string
    {
        $chain = str_pad($nit, 13, '0', STR_PAD_LEFT)
            .$timestamp
            .str_pad((string) $sucursal, 4, '0', STR_PAD_LEFT)
            .$modalidad
            .$emision
            .'1'  // tipo de factura: con derecho a crédito fiscal
            .'01' // documento sector: compra y venta
            .str_pad((string) $numeroFactura, 10, '0', STR_PAD_LEFT)
            .str_pad((string) $puntoVenta, 4, '0', STR_PAD_LEFT);

        return $this->base16($chain.$this->mod11($chain)).$codigoControl;
    }

    /** El residuo 10 se representa con "1" (no "10"). */
    private function mod11(string $value): string
    {
        $sum = 0;
        $multiplier = 2;
        for ($index = strlen($value) - 1; $index >= 0; $index--) {
            $sum += $multiplier * (int) $value[$index];
            $multiplier = $multiplier === 9 ? 2 : $multiplier + 1;
        }
        $digit = $sum % 11;

        return $digit === 10 ? '1' : (string) $digit;
    }

    private function base16(string $number): string
    {
        $hex = '';
        while (bccomp($number, '0') > 0) {
            $hex = strtoupper(dechex((int) bcmod($number, '16'))).$hex;
            $number = bcdiv($number, '16', 0);
        }

        return $hex ?: '0';
    }
}
