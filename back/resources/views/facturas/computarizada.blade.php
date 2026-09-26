@php($offline = $sale->estado_siat === 'PENDIENTE_EVENTO' || (int) $sale->tipo_emision === 2)
<!doctype html><html lang="es"><head><meta charset="UTF-8"><style>
@page{margin:12px}body{font-family:DejaVu Sans,sans-serif;font-size:8px;color:#111;margin:0}.center{text-align:center}.right{text-align:right}.bold{font-weight:bold}
.title{font-size:12px}.line{border-top:1px dashed #222;margin:6px 0}.break{word-break:break-all}table{width:100%;border-collapse:collapse}td{padding:1px 2px;vertical-align:top}
.big{font-size:10px;font-weight:bold}.cancelled{font-size:24px;color:#b71c1c;text-align:center;font-weight:bold;margin:6px}.qr{width:110px;height:110px}.small{font-size:7px}
</style></head><body>
<div class="center"><div class="title bold">FACTURA</div><div>CON DERECHO A CRÉDITO FISCAL</div><div class="bold">{{ $company?->nombre_empresa }}</div>
<div>{{ (int) config('siat.sucursal') === 0 ? 'CASA MATRIZ' : 'SUCURSAL N° '.config('siat.sucursal') }}</div><div>No. Punto de Venta {{ config('siat.punto_venta') }}</div>
<div>{{ $company?->direccion }}</div><div>Teléfono: {{ $company?->telefono }}</div><div>{{ mb_strtoupper((string) config('siat.municipio')) }}</div></div>
<div class="line"></div>
<div class="center"><b>NIT</b><br>{{ config('siat.nit') }}<br><b>FACTURA N°</b><br>{{ $sale->numero_factura }}<br><b>CÓD. AUTORIZACIÓN</b><br><span class="break">{{ $sale->cuf }}</span></div>
<div class="line"></div>
<table><tr><td class="bold">NOMBRE/RAZÓN SOCIAL:</td><td>{{ $sale->cliente_nombre }}</td></tr><tr><td class="bold">NIT/CI/CEX:</td><td>{{ $sale->numero_documento }}{{ $sale->complemento ? '-'.$sale->complemento : '' }}</td></tr>
<tr><td class="bold">COD. CLIENTE:</td><td>{{ $sale->cliente_id ?: $sale->numero_documento }}</td></tr><tr><td class="bold">FECHA DE EMISIÓN:</td><td>{{ optional($sale->fecha_emision_siat ?: $sale->fecha)->format('d/m/Y H:i:s') }}</td></tr></table>
<div class="line"></div><div class="center bold">DETALLE</div>
<table>@foreach($sale->detalles as $item)<tr><td colspan="2" class="bold">{{ $item->codigo }} - {{ $item->nombre }}</td></tr>
<tr><td>{{ number_format((float) $item->cantidad, $item->unidad === 'KG' ? 3 : 0) }} {{ $item->unidad }} × {{ number_format((float) $item->precio_venta, 2) }}@if((float) $item->descuento) - {{ number_format((float) $item->descuento, 2) }}@endif</td><td class="right">{{ number_format((float) $item->total, 2) }}</td></tr>@endforeach</table>
<div class="line"></div>
<table><tr><td>SUBTOTAL Bs</td><td class="right">{{ number_format((float) $sale->subtotal, 2) }}</td></tr><tr><td>DESCUENTO Bs</td><td class="right">{{ number_format((float) $sale->descuento, 2) }}</td></tr>
<tr><td>TOTAL Bs</td><td class="right">{{ number_format((float) $sale->total, 2) }}</td></tr><tr><td>MONTO GIFT CARD Bs</td><td class="right">0.00</td></tr>
<tr class="big"><td>MONTO A PAGAR Bs</td><td class="right">{{ number_format((float) $sale->total, 2) }}</td></tr><tr class="bold"><td>IMPORTE BASE CRÉDITO FISCAL Bs</td><td class="right">{{ number_format((float) $sale->total, 2) }}</td></tr></table>
<div>Son: {{ $literal }} Bolivianos</div>
<div class="line"></div>
<div class="center small">ESTA FACTURA CONTRIBUYE AL DESARROLLO DEL PAÍS, EL USO ILÍCITO SERÁ SANCIONADO PENALMENTE DE ACUERDO A LEY</div>
<p class="center small">{{ $sale->leyenda }}</p>
<p class="center small">@if($offline)“Este documento es la Representación Gráfica de un Documento Fiscal Digital emitido fuera de línea, verifique su envío con su proveedor o en la página web www.impuestos.gob.bo”@else“Este documento es la Representación Gráfica de un Documento Fiscal Digital emitido en una modalidad de facturación en línea”@endif</p>
<div class="center"><img class="qr" src="{{ $qr }}" alt="QR"></div>
@if($sale->estado === 'ANULADA' || $sale->estado_siat === 'ANULADA')<div class="cancelled">ANULADA</div>@endif
</body></html>
