<!doctype html>
<html lang="es"><head><meta charset="utf-8"><style>
body{font-family:DejaVu Sans,sans-serif;font-size:9px;color:#263238}h2{margin:0;color:#b71c1c}.meta{margin:3px 0;color:#607d8b}
table{width:100%;border-collapse:collapse;margin-top:10px}th{background:#b71c1c;color:#fff;padding:6px;text-align:left}td{padding:5px;border-bottom:1px solid #ddd}.right{text-align:right}.down{color:#c62828;font-weight:bold}.up{color:#ef6c00;font-weight:bold}
</style></head><body>
<h2>{{ mb_strtoupper($meta['empresa'] ?? 'Bajo Cero') }} — PRECIOS MODIFICADOS</h2>
<div class="meta">Período: {{ $meta['periodo'] }}</div><div class="meta">Filtros: {{ $meta['filtros'] }}</div><div class="meta">Exportado por: {{ $meta['exportado_por'] }} · {{ $meta['exportado_en'] }}</div>
<table><thead><tr><th>Venta</th><th>Fecha</th><th>Código</th><th>Producto</th><th class="right">Cantidad</th><th class="right">Precio base</th><th class="right">Precio aplicado</th><th class="right">Diferencia</th><th class="right">Impacto</th><th>Usuario</th><th>Estado</th></tr></thead>
<tbody>@forelse($detalles as $fila) @php($v=$fila['venta']) @php($d=$fila['detalle'])
<tr><td>{{$v->numero}}</td><td>{{$v->fecha->format('d/m/Y H:i')}}</td><td>{{$d->codigo}}</td><td>{{$d->nombre}}</td><td class="right">{{number_format($d->cantidad,3)}}</td><td class="right">Bs {{number_format($d->precio_base,2)}}</td><td class="right">Bs {{number_format($d->precio_venta,2)}}</td><td class="right {{$fila['diferencia']<0?'down':'up'}}">Bs {{number_format($fila['diferencia'],2)}}</td><td class="right">Bs {{number_format($fila['diferencia']*$d->cantidad,2)}}</td><td>{{$v->usuario_nombre}}</td><td>{{$v->estado}}</td></tr>
@empty <tr><td colspan="11" style="text-align:center;padding:20px">No existen precios modificados para los filtros seleccionados.</td></tr> @endforelse</tbody></table>
</body></html>
