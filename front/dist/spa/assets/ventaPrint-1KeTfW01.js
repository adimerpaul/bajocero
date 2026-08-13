import{n as e,r as t}from"./empresa-DAyNCcuj.js";import{t as n}from"./printd-CI3YqDZj.js";var r=n(),i=`
@page{size:80mm auto;margin:4mm}body{margin:0}.ticket{font-family:Arial,sans-serif;font-size:11px;color:#111}
h2{text-align:center;margin:0}.center{text-align:center}.line{border-top:1px dashed #333;margin:7px 0}
.logo{display:block;width:70px;max-height:70px;object-fit:contain;margin:0 auto 4px}
table{width:100%;border-collapse:collapse}th,td{padding:2px}.right{text-align:right}.bold{font-weight:bold}
.total{font-size:15px}.cancelled{font-size:28px;color:#c62828;text-align:center;font-weight:bold}
.pending{border:1px dashed #c62828;color:#c62828;text-align:center;font-weight:bold;padding:3px;margin-top:4px}
`,a=e=>String(e??``).replaceAll(`&`,`&amp;`).replaceAll(`<`,`&lt;`).replaceAll(`>`,`&gt;`),o=e=>Number(e||0).toFixed(2);function s(n){let s=e(),c=t(),l=(n.detalles||[]).map(e=>`<tr><td>${a(e.nombre)}<br><small>${e.unidad===`KG`?Number(e.cantidad).toFixed(3):Number(e.cantidad).toFixed(0)} ${a(e.unidad)} × ${o(e.precio_venta)}</small></td><td class="right">${o(e.total)}</td></tr>`).join(``),u=document.createElement(`div`);u.innerHTML=`<div class="ticket"><img class="logo" src="${c}" alt="Bajo Cero"><h2>${a(s.nombre_empresa||`Bajo Cero`)}</h2><div class="center">${a(s.direccion||``)}<br>Tel: ${a(s.telefono||``)} ${s.nit?`· NIT: ${a(s.nit)}`:``}<br><b>COMPROBANTE DE VENTA</b></div><div class="line"></div>
  <div><b>${a(n.numero)}</b><br>Fecha: ${new Date(n.fecha).toLocaleString(`es-BO`)}<br>Cajero: ${a(n.usuario_nombre)}<br>Pago: ${a(n.tipo_pago)}</div>
  <div class="line"></div><table><thead><tr><th>Producto</th><th class="right">Total</th></tr></thead><tbody>${l}</tbody></table><div class="line"></div>
  <table><tr><td>Subtotal</td><td class="right">${o(n.subtotal)}</td></tr><tr><td>Descuento</td><td class="right">-${o(n.descuento)}</td></tr>
  <tr><td>Efectivo</td><td class="right">${o(n.monto_efectivo)}</td></tr><tr><td>QR</td><td class="right">${o(n.monto_qr)}</td></tr>
  <tr class="bold total"><td>TOTAL Bs</td><td class="right">${o(n.total)}</td></tr></table>
  ${n.estado===`ANULADA`?`<div class="cancelled">ANULADA</div>`:``}
  ${n.pendiente?`<div class="pending">VENTA OFFLINE<br>PENDIENTE DE ENVÍO</div>`:``}<div class="line"></div><div class="center">¡Gracias por su compra!</div></div>`,new r.Printd().print(u,[i])}export{s as t};