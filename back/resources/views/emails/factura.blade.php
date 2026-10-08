<div style="font-family:Arial,sans-serif;max-width:620px;margin:auto;color:#263238">
    <div style="background:#b71c1c;color:white;padding:22px;border-radius:12px 12px 0 0">
        <h2 style="margin:0">
            @if($revertida ?? false)
                Factura restablecida (Anulación revertida)
            @elseif($anulada)
                Factura anulada
            @else
                ¡Gracias por su compra!
            @endif
        </h2>
        <div>{{ $empresa }}</div>
    </div>
    <div style="padding:22px;border:1px solid #eceff1;border-top:0;border-radius:0 0 12px 12px">
        <p>Estimado(a) {{ $sale->cliente_nombre ?: 'cliente' }},</p>
        @if($revertida ?? false)
            <p>Le informamos que la anulación de su factura ha sido <b>revertida</b> ante Impuestos Nacionales. Su factura vuelve a estar <b>vigente y validada</b>. Adjuntamos la representación gráfica en PDF y el archivo XML oficial.</p>
        @elseif($anulada)
            <p>Le informamos que la siguiente factura fue <b>anulada</b> ante Impuestos Nacionales. Adjuntamos la representación gráfica en PDF con la marca ANULADA y el archivo XML oficial de la factura.</p>
        @elseif($sale->estado_siat === 'PENDIENTE_EVENTO')
            <p>Su factura fue emitida <b>fuera de línea</b>. Adjuntamos la representación gráfica en PDF y el archivo XML.</p>
            <p style="background:#fff3e0;color:#e65100;padding:10px;border-radius:6px"><b>Importante:</b> la factura se enviará a Impuestos Nacionales en cuanto se restablezca la comunicación.</p>
        @else
            <p>Su factura fue validada por Impuestos Nacionales. Adjuntamos la representación gráfica en PDF y el archivo XML.</p>
        @endif
        <div style="background:#fbe9e7;padding:14px;border-radius:8px;line-height:1.6">
            <div><b>Factura:</b> N° {{ $sale->numero_factura }}</div>
            @if($sale->cuf)
                <div style="margin:4px 0;word-break:break-all"><b>CUF (Código de Autorización):</b><br><span style="font-family:monospace;font-size:11px;color:#37474f;background:#fff;padding:2px 6px;border-radius:4px;border:1px solid #ffccbc;display:inline-block">{{ $sale->cuf }}</span></div>
            @endif
            <div><b>Fecha:</b> {{ optional($sale->fecha_emision_siat ?: $sale->fecha)->format('d/m/Y H:i') }}</div>
            <div><b>Total:</b> Bs {{ number_format((float) $sale->total, 2) }}</div>
            @if($motivo && !($revertida ?? false))<div><b>Motivo de anulación:</b> {{ $motivo }}</div>@endif
        </div>
        @if($sale->factura_url)<p style="margin-top:16px"><a href="{{ $sale->factura_url }}" style="color:#b71c1c;font-weight:bold">Verificar la factura en Impuestos Nacionales</a></p>@endif
        <p style="color:#607d8b;font-size:12px;margin-top:16px">Este correo fue generado automáticamente. Conserve los archivos adjuntos.</p>
    </div>
</div>
