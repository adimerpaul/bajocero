import { Printd } from 'printd'
import QRCode from 'qrcode'
import { companyData, companyLogo } from './empresa'
import { numeroALetras } from './numeroALetras'

const css = `
@page{size:80mm auto;margin:4mm}body{margin:0}.ticket{font-family:Arial,sans-serif;font-size:11px;color:#111}
h2{text-align:center;margin:0}.center{text-align:center}.line{border-top:1px dashed #333;margin:7px 0}
.logo{display:block;width:70px;max-height:70px;object-fit:contain;margin:0 auto 4px}
table{width:100%;border-collapse:collapse}th,td{padding:2px;vertical-align:top}.right{text-align:right}.bold{font-weight:bold}
.total{font-size:15px}.cancelled{font-size:28px;color:#c62828;text-align:center;font-weight:bold}
.pending{border:1px dashed #c62828;color:#c62828;text-align:center;font-weight:bold;padding:3px;margin-top:4px}
.small{font-size:9px}.cuf{word-break:break-all;font-size:10px}.qr{display:block;width:120px;height:120px;margin:4px auto}
`
const esc = value => String(value ?? '').replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;')
const money = value => Number(value || 0).toFixed(2)
const qty = item => item.unidad === 'KG' ? Number(item.cantidad).toFixed(3) : Number(item.cantidad).toFixed(0)

/** Decide solo el formato: factura de Impuestos si la venta se facturó (tiene CUF), si no el comprobante. */
export async function printSale (sale) {
  const element = document.createElement('div')
  const factura = sale.tipo_comprobante === 'FACTURA' && sale.cuf
  element.innerHTML = factura ? await invoiceHtml(sale) : receiptHtml(sale)
  new Printd().print(element, [css])
}

function receiptHtml (sale) {
  const company = companyData()
  const logoUrl = companyLogo() // copia en base64 de localStorage: imprime sin conexión
  const rows = (sale.detalles || []).map(item => `<tr><td>${esc(item.nombre)}<br><small>${qty(item)} ${esc(item.unidad)} × ${money(item.precio_venta)}</small></td><td class="right">${money(item.total)}</td></tr>`).join('')

  return `<div class="ticket"><img class="logo" src="${logoUrl}" alt="Bajo Cero"><h2>${esc(company.nombre_empresa||'Bajo Cero')}</h2><div class="center">${esc(company.direccion||'')}<br>Tel: ${esc(company.telefono||'')} ${company.nit?`· NIT: ${esc(company.nit)}`:''}<br><b>COMPROBANTE DE VENTA</b></div><div class="line"></div>
  <div><b>${esc(sale.numero)}</b><br>Fecha: ${new Date(sale.fecha).toLocaleString('es-BO')}<br>Cajero: ${esc(sale.usuario_nombre)}<br>Pago: ${esc(sale.tipo_pago)}</div>
  <div class="line"></div><table><thead><tr><th>Producto</th><th class="right">Total</th></tr></thead><tbody>${rows}</tbody></table><div class="line"></div>
  <table><tr><td>Subtotal</td><td class="right">${money(sale.subtotal)}</td></tr><tr><td>Descuento</td><td class="right">-${money(sale.descuento)}</td></tr>
  <tr><td>Efectivo</td><td class="right">${money(sale.monto_efectivo)}</td></tr><tr><td>QR</td><td class="right">${money(sale.monto_qr)}</td></tr>
  <tr class="bold total"><td>TOTAL Bs</td><td class="right">${money(sale.total)}</td></tr></table>
  ${sale.estado === 'ANULADA' ? '<div class="cancelled">ANULADA</div>' : ''}
  ${sale.pendiente ? `<div class="pending">VENTA OFFLINE<br>PENDIENTE DE ENVÍO${sale.tipo_comprobante === 'FACTURA' ? '<br>La factura se emite al enviarla' : ''}</div>` : ''}<div class="line"></div><div class="center">¡Gracias por su compra!</div></div>`
}

/** Representación gráfica de la factura computarizada en rollo (formato del SIN). */
async function invoiceHtml (sale) {
  const company = companyData()
  const siat = company.siat || {}
  let qr = ''
  try { qr = sale.factura_url ? await QRCode.toDataURL(sale.factura_url, { margin: 1, width: 240 }) : '' } catch { /* sin QR se imprime igual */ }
  const document = `${esc(sale.numero_documento)}${sale.complemento ? '-' + esc(sale.complemento) : ''}`
  const rows = (sale.detalles || []).map(item => `<tr><td colspan="2"><b>${esc(item.codigo)} - ${esc(item.nombre)}</b></td></tr><tr><td>${qty(item)} ${esc(item.unidad)} × ${money(item.precio_venta)}${Number(item.descuento) ? ` - ${money(item.descuento)}` : ''}</td><td class="right">${money(item.total)}</td></tr>`).join('')
  const offline = sale.estado_siat === 'PENDIENTE_EVENTO' || Number(sale.tipo_emision) === 2

  return `<div class="ticket"><div class="center"><b>FACTURA</b><br>CON DERECHO A CRÉDITO FISCAL<br><b>${esc(company.nombre_empresa||'Bajo Cero')}</b><br>${Number(siat.sucursal||0) === 0 ? 'CASA MATRIZ' : 'SUCURSAL N° ' + esc(siat.sucursal)}<br>No. Punto de Venta ${esc(siat.punto_venta ?? 0)}<br>${esc(company.direccion||'')}<br>Teléfono: ${esc(company.telefono||'')}<br>${esc((siat.municipio||'').toUpperCase())}</div>
  <div class="line"></div>
  <div class="center"><b>NIT</b><br>${esc(siat.nit || company.nit || '')}<br><b>FACTURA N°</b><br>${esc(sale.numero_factura)}<br><b>CÓD. AUTORIZACIÓN</b><br><span class="cuf">${esc(sale.cuf)}</span></div>
  <div class="line"></div>
  <table><tr><td class="bold">NOMBRE/RAZÓN SOCIAL:</td><td>${esc(sale.cliente_nombre)}</td></tr><tr><td class="bold">NIT/CI/CEX:</td><td>${document}</td></tr><tr><td class="bold">COD. CLIENTE:</td><td>${esc(sale.cliente_id || sale.numero_documento)}</td></tr><tr><td class="bold">FECHA DE EMISIÓN:</td><td>${new Date(sale.fecha_emision_siat || sale.fecha).toLocaleString('es-BO')}</td></tr></table>
  <div class="line"></div><div class="center bold">DETALLE</div>
  <table><tbody>${rows}</tbody></table><div class="line"></div>
  <table><tr><td>SUBTOTAL Bs</td><td class="right">${money(sale.subtotal)}</td></tr><tr><td>DESCUENTO Bs</td><td class="right">${money(sale.descuento)}</td></tr>
  <tr><td>TOTAL Bs</td><td class="right">${money(sale.total)}</td></tr><tr><td>MONTO GIFT CARD Bs</td><td class="right">0.00</td></tr>
  <tr class="bold total"><td>MONTO A PAGAR Bs</td><td class="right">${money(sale.total)}</td></tr><tr class="bold"><td>IMPORTE BASE CRÉDITO FISCAL Bs</td><td class="right">${money(sale.total)}</td></tr></table>
  <div>Son: ${numeroALetras(sale.total)} Bolivianos</div>
  <div class="line"></div>
  <div class="small">Efectivo: Bs ${money(sale.monto_efectivo)} · QR: Bs ${money(sale.monto_qr)} · Venta ${esc(sale.numero)} · Cajero: ${esc(sale.usuario_nombre)}</div>
  <div class="line"></div>
  <div class="center small">ESTA FACTURA CONTRIBUYE AL DESARROLLO DEL PAÍS, EL USO ILÍCITO SERÁ SANCIONADO PENALMENTE DE ACUERDO A LEY</div>
  <div class="center small" style="margin-top:4px">${esc(sale.leyenda || '')}</div>
  <div class="center small" style="margin-top:4px">${offline
    ? '“Este documento es la Representación Gráfica de un Documento Fiscal Digital emitido fuera de línea, verifique su envío con su proveedor o en la página web www.impuestos.gob.bo”'
    : '“Este documento es la Representación Gráfica de un Documento Fiscal Digital emitido en una modalidad de facturación en línea”'}</div>
  ${qr ? `<img class="qr" src="${qr}" alt="QR">` : ''}
  ${sale.estado === 'ANULADA' || sale.estado_siat === 'ANULADA' ? '<div class="cancelled">ANULADA</div>' : ''}</div>`
}
