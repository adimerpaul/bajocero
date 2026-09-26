<div style="font-family:Arial,sans-serif;max-width:620px;margin:auto;color:#263238">
    <div style="background:#b71c1c;color:white;padding:22px;border-radius:12px 12px 0 0">
        <h2 style="margin:0">{{ $anulada ? 'Factura anulada' : '¡Gracias por su compra!' }}</h2>
        <div>{{ $empresa }}</div>
    </div>
    <div style="padding:22px;border:1px solid #eceff1;border-top:0;border-radius:0 0 12px 12px">
        <p>Estimado(a) {{ $sale->cliente_nombre ?: 'cliente' }},</p>
        @if($anulada)
            <p>Le informamos que la siguiente factura fue <b>anulada</b> ante Impuestos Nacionales. Adjuntamos la representación gráfica con la marca ANULADA.</p>
        @elseif($sale->estado_siat === 'PENDIENTE_EVENTO')
            <p>Su factura fue emitida <b>fuera de línea</b>. Adjuntamos la representación gráfica en PDF y el archivo XML.</p>
            <p style="background:#fff3e0;color:#e65100;padding:10px;border-radius:6px"><b>Importante:</b> la factura se enviará a Impuestos Nacionales en cuanto se restablezca la comunicación.</p>
        @else
            <p>Su factura fue validada por Impuestos Nacionales. Adjuntamos la representación gráfica en PDF y el archivo XML.</p>
        @endif
        <div style="background:#fbe9e7;padding:14px;border-radius:8px">
            <div><b>Factura:</b> N° {{ $sale->numero_factura }}</div>
            <div><b>Fecha:</b> {{ optional($sale->fecha_emision_siat ?: $sale->fecha)->format('d/m/Y H:i') }}</div>
            <div><b>Total:</b> Bs {{ number_format((float) $sale->total, 2) }}</div>
            @if($motivo)<div><b>Motivo de anulación:</b> {{ $motivo }}</div>@endif
        </div>
        @if($sale->factura_url)<p><a href="{{ $sale->factura_url }}">Verificar la factura en Impuestos Nacionales</a></p>@endif
        <p style="color:#607d8b;font-size:12px">Este correo fue generado automáticamente. Conserve los archivos adjuntos.</p>
    </div>
</div>
