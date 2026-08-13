import{n as e,r as t}from"./empresa-DAyNCcuj.js";import{t as n}from"./printd-CI3YqDZj.js";var r=n(),i=`
@page{size:80mm auto;margin:4mm}body{margin:0}.ticket{font-family:Arial,sans-serif;font-size:11px;color:#111}
h2{text-align:center;margin:0}.center{text-align:center}.line{border-top:1px dashed #333;margin:7px 0}
.logo{display:block;width:70px;max-height:70px;object-fit:contain;margin:0 auto 4px}
table{width:100%;border-collapse:collapse}th,td{padding:2px}.right{text-align:right}.bold{font-weight:bold}
.total{font-size:15px}.motivo{text-align:center;border:1px solid #333;padding:3px;font-weight:bold;margin:5px 0}
.cancelled{font-size:28px;color:#f57c00;text-align:center;font-weight:bold}
.sign{margin-top:26px;border-top:1px solid #333;text-align:center;padding-top:3px}
`,a=e=>String(e??``).replaceAll(`&`,`&amp;`).replaceAll(`<`,`&lt;`).replaceAll(`>`,`&gt;`),o=e=>Number(e||0).toFixed(2),s=(e,t)=>Number(e||0).toFixed(t===`KG`?3:0);function c(n){let c=e(),l=t(),u=(n.detalles||[]).map(e=>`<tr><td>${a(e.nombre)}<br><small>${s(e.cantidad,e.unidad)} ${a(e.unidad)} × ${o(e.precio_compra)}${e.observacion?` · ${a(e.observacion)}`:``}</small></td><td class="right">${o(e.total)}</td></tr>`).join(``),d=document.createElement(`div`);d.innerHTML=`<div class="ticket"><img class="logo" src="${l}" alt="Bajo Cero"><h2>${a(c.nombre_empresa||`Bajo Cero`)}</h2><div class="center">${a(c.direccion||``)}<br>Tel: ${a(c.telefono||``)} ${c.nit?`· NIT: ${a(c.nit)}`:``}<br><b>COMPROBANTE DE BAJA</b></div><div class="line"></div>
  <div><b>${a(n.numero)}</b><br>Fecha: ${new Date(n.fecha).toLocaleString(`es-BO`)}<br>Registró: ${a(n.usuario_nombre)}</div>
  <div class="motivo">${a(n.motivo)}</div>${n.observacion?`<div>Obs: ${a(n.observacion)}</div>`:``}
  <div class="line"></div><table><thead><tr><th>Producto</th><th class="right">Costo</th></tr></thead><tbody>${u}</tbody></table><div class="line"></div>
  <table><tr class="bold total"><td>COSTO TOTAL Bs</td><td class="right">${o(n.total_costo)}</td></tr></table>
  ${n.estado===`ANULADA`?`<div class="cancelled">ANULADA</div>`:`<div class="sign">${a(n.usuario_nombre)}<br><small>Responsable de la baja</small></div>`}</div>`,new r.Printd().print(d,[i])}export{c as t};