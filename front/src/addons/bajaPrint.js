import { Printd } from 'printd'
import { companyData } from './empresa'

const css = `
@page{size:80mm auto;margin:4mm}body{margin:0}.ticket{font-family:Arial,sans-serif;font-size:11px;color:#111}
h2{text-align:center;margin:0}.center{text-align:center}.line{border-top:1px dashed #333;margin:7px 0}
.logo{display:block;width:70px;max-height:70px;object-fit:contain;margin:0 auto 4px}
table{width:100%;border-collapse:collapse}th,td{padding:2px}.right{text-align:right}.bold{font-weight:bold}
.total{font-size:15px}.motivo{text-align:center;border:1px solid #333;padding:3px;font-weight:bold;margin:5px 0}
.cancelled{font-size:28px;color:#f57c00;text-align:center;font-weight:bold}
.sign{margin-top:26px;border-top:1px solid #333;text-align:center;padding-top:3px}
`
const esc = value => String(value ?? '').replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;')
const money = value => Number(value || 0).toFixed(2)
const qty = (value, unit) => Number(value || 0).toFixed(unit === 'KG' ? 3 : 0)

export function printBaja (baja) {
  const company = companyData()
  const logoUrl = company.logo_url || `${window.location.origin}/bajo-cero-logo.svg`
  const rows = (baja.detalles || []).map(item => `<tr><td>${esc(item.nombre)}<br><small>${qty(item.cantidad, item.unidad)} ${esc(item.unidad)} × ${money(item.precio_compra)}${item.observacion ? ` · ${esc(item.observacion)}` : ''}</small></td><td class="right">${money(item.total)}</td></tr>`).join('')
  const element = document.createElement('div')
  element.innerHTML = `<div class="ticket"><img class="logo" src="${logoUrl}" alt="Bajo Cero"><h2>${esc(company.nombre_empresa||'Bajo Cero')}</h2><div class="center">${esc(company.direccion||'')}<br>Tel: ${esc(company.telefono||'')} ${company.nit?`· NIT: ${esc(company.nit)}`:''}<br><b>COMPROBANTE DE BAJA</b></div><div class="line"></div>
  <div><b>${esc(baja.numero)}</b><br>Fecha: ${new Date(baja.fecha).toLocaleString('es-BO')}<br>Registró: ${esc(baja.usuario_nombre)}</div>
  <div class="motivo">${esc(baja.motivo)}</div>${baja.observacion?`<div>Obs: ${esc(baja.observacion)}</div>`:''}
  <div class="line"></div><table><thead><tr><th>Producto</th><th class="right">Costo</th></tr></thead><tbody>${rows}</tbody></table><div class="line"></div>
  <table><tr class="bold total"><td>COSTO TOTAL Bs</td><td class="right">${money(baja.total_costo)}</td></tr></table>
  ${baja.estado === 'ANULADA' ? '<div class="cancelled">ANULADA</div>' : `<div class="sign">${esc(baja.usuario_nombre)}<br><small>Responsable de la baja</small></div>`}</div>`
  new Printd().print(element, [css])
}
