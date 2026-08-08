import{n as e,t}from"./empresa-J64U-cjD.js";var n=e(),r=`
@page{size:80mm auto;margin:4mm}body{margin:0}.ticket{font-family:Arial,sans-serif;font-size:11px;color:#111}
h2{text-align:center;margin:0}.center{text-align:center}.line{border-top:1px dashed #333;margin:7px 0}
.logo{display:block;width:70px;max-height:70px;object-fit:contain;margin:0 auto 4px}
table{width:100%;border-collapse:collapse}th,td{padding:2px}.right{text-align:right}.bold{font-weight:bold}
.total{font-size:15px}.motivo{text-align:center;border:1px solid #333;padding:3px;font-weight:bold;margin:5px 0}
.cancelled{font-size:28px;color:#f57c00;text-align:center;font-weight:bold}
.sign{margin-top:26px;border-top:1px solid #333;text-align:center;padding-top:3px}
`,i=e=>String(e??``).replaceAll(`&`,`&amp;`).replaceAll(`<`,`&lt;`).replaceAll(`>`,`&gt;`),a=e=>Number(e||0).toFixed(2),o=(e,t)=>Number(e||0).toFixed(t===`KG`?3:0);function s(e){let s=t(),c=s.logo_url||`${window.location.origin}/bajo-cero-logo.svg`,l=(e.detalles||[]).map(e=>`<tr><td>${i(e.nombre)}<br><small>${o(e.cantidad,e.unidad)} ${i(e.unidad)} × ${a(e.precio_compra)}${e.observacion?` · ${i(e.observacion)}`:``}</small></td><td class="right">${a(e.total)}</td></tr>`).join(``),u=document.createElement(`div`);u.innerHTML=`<div class="ticket"><img class="logo" src="${c}" alt="Bajo Cero"><h2>${i(s.nombre_empresa||`Bajo Cero`)}</h2><div class="center">${i(s.direccion||``)}<br>Tel: ${i(s.telefono||``)} ${s.nit?`· NIT: ${i(s.nit)}`:``}<br><b>COMPROBANTE DE BAJA</b></div><div class="line"></div>
  <div><b>${i(e.numero)}</b><br>Fecha: ${new Date(e.fecha).toLocaleString(`es-BO`)}<br>Registró: ${i(e.usuario_nombre)}</div>
  <div class="motivo">${i(e.motivo)}</div>${e.observacion?`<div>Obs: ${i(e.observacion)}</div>`:``}
  <div class="line"></div><table><thead><tr><th>Producto</th><th class="right">Costo</th></tr></thead><tbody>${l}</tbody></table><div class="line"></div>
  <table><tr class="bold total"><td>COSTO TOTAL Bs</td><td class="right">${a(e.total_costo)}</td></tr></table>
  ${e.estado===`ANULADA`?`<div class="cancelled">ANULADA</div>`:`<div class="sign">${i(e.usuario_nombre)}<br><small>Responsable de la baja</small></div>`}</div>`,new n.Printd().print(u,[r])}export{s as t};