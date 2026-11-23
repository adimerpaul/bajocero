import { STYLES as S, DXF, buildWorkbook, cfCell, cfRule, cfZebra, col, empty, fx, fxStr, headerMerges, headerRows, num, r2, r3, row, sheetClose, sheetOpen, str, tick } from './xlsx.js'

/**
 * Excel del avance de una revisión de inventario, con el formato de la planilla en papel:
 *
 *   Producto | ADIMER | JOSE | FRANZ | Total contado | Sistema | Diferencia
 *
 * Una columna por cada persona que contó; el total es la suma (fórmula) y se compara
 * con el stock del sistema. Fila de datos (GET /almacenes/{id}/exportar-datos):
 * [codigo, nombre, unidad, [[user_id, usuario, cantidad], …], total, sistema, precio_compra, lotes]
 */

const CHUNK = 3000
const HEADER_ROW = 7
const FIRST_ROW = 8
const userKey = (id, nombre) => id ? `u${id}` : `n${nombre}`
const estadoDe = d => Math.abs(d) < 0.0005 ? 'CUADRA' : d > 0 ? 'SOBRA' : 'FALTA'

async function writeConteo (push, rows, users, meta, onProgress, signal) {
  const U = users.length
  const fixedLeft = [{ h: 'N°', w: 6 }, { h: 'Código', w: 11 }, { h: 'Producto', w: 38 }, { h: 'Unidad', w: 8 }]
  const userCols = users.map(u => ({ h: u.usuario, w: Math.min(Math.max(u.usuario.length * 1.1, 10), 18), user: true }))
  const fixedRight = [{ h: 'Total contado', w: 12 }, { h: 'Sistema', w: 11 }, { h: 'Diferencia', w: 11 }, { h: 'Estado', w: 10 }, { h: 'Costo unit.', w: 11 }, { h: 'Valor diferencia', w: 14 }, { h: 'Lotes / vencimientos', w: 34 }]
  const cols = [...fixedLeft, ...userCols, ...fixedRight]
  const LAST = col(cols.length)
  const firstUser = 5, lastUser = 4 + U
  const cTotal = col(5 + U), cSis = col(6 + U), cDif = col(7 + U), cEst = col(8 + U), cCosto = col(9 + U), cValor = col(10 + U)
  const maxName = rows.reduce((m, r) => Math.max(m, String(r[1] ?? '').length), 20)
  cols[2] = { ...cols[2], w: Math.min(Math.max(maxName * 0.95, 24), 55) }
  const index = new Map(users.map((u, i) => [u.key, i]))
  const stats = { revisados: rows.length, cuadran: 0, sobrantes: 0, faltantes: 0, sobra: 0, falta: 0, valor: 0 }
  const last = FIRST_ROW + rows.length - 1

  push(sheetOpen({ cols, freeze: { x: 3, y: HEADER_ROW }, tab: 'FFEF6C00' }) + headerRows(meta, 'CONTEO DE INVENTARIO POR PERSONA') +
    row(6, '') + row(HEADER_ROW, cols.map(c => str(c.h, c.user ? S.headUser : S.head)).join(''), 32))

  for (let start = 0; start < rows.length; start += CHUNK) {
    if (signal?.aborted) throw new DOMException('Exportación cancelada', 'AbortError')
    let xml = ''
    const end = Math.min(start + CHUNK, rows.length)
    for (let i = start; i < end; i++) {
      const [codigo, nombre, unidad, porUsuario, total, sistema, precio, lotes] = rows[i]
      const r = FIRST_ROW + i
      const qtys = new Array(U).fill(null)
      for (const [id, usuario, cantidad] of porUsuario || []) {
        const k = index.get(userKey(id, usuario))
        if (k !== undefined) qtys[k] = (qtys[k] || 0) + Number(cantidad || 0)
      }
      const contado = r3(Number(total) || 0), sis = r3(Number(sistema) || 0), dif = r3(contado - sis), costo = Number(precio) || 0, valor = r2(dif * costo)
      const estado = estadoDe(dif)
      if (estado === 'CUADRA') stats.cuadran++
      else if (dif > 0) { stats.sobrantes++; stats.sobra += dif } else { stats.faltantes++; stats.falta -= dif }
      stats.valor += valor

      xml += `<row r="${r}">` + num(i + 1, S.num) + str(codigo, S.code) + str(nombre, S.text) + str(unidad, S.center) +
        qtys.map(q => q === null ? empty(S.qtyUser) : num(r3(q), S.qtyUser)).join('') +
        (U ? fx(`SUM(${col(firstUser)}${r}:${col(lastUser)}${r})`, contado, S.qtyBold) : num(contado, S.qtyBold)) +
        num(sis, S.qty) + fx(`${cTotal}${r}-${cSis}${r}`, dif, S.qty) + str(estado, S.estado) +
        num(costo, S.money) + fx(`ROUND(${cDif}${r}*${cCosto}${r},2)`, valor, S.money) + str(lotes || '', S.text) + '</row>'
    }
    push(xml)
    onProgress(end)
    await tick()
  }

  // Pie: cuántos productos contó cada uno y el total del valor de la diferencia (respetan el filtro).
  const has = rows.length > 0, rng = c => `${c}${FIRST_ROW}:${c}${last}`
  const t1 = last + 1, t2 = last + 2
  push(row(t1, str('', S.totLabel) + str('', S.totLabel) + str('Productos contados por persona', S.totLabel) + str('', S.totLabel) +
    users.map((u, k) => has ? fx(`SUBTOTAL(102,${rng(col(firstUser + k))})`, u.productos, S.totInt) : num(0, S.totInt)).join('') +
    (has ? fx(`SUBTOTAL(103,${rng('C')})`, rows.length, S.totInt) : num(0, S.totInt)) +
    Array(6).fill(str('', S.totLabel)).join(''), 20))
  push(row(t2, str('', S.totLabel) + str('', S.totLabel) +
    (has ? fxStr(`"TOTAL: "&SUBTOTAL(103,${rng('C')})&" productos revisados"`, `TOTAL: ${rows.length} productos revisados`, S.totLabel) : str('TOTAL: 0 productos', S.totLabel)) +
    str('', S.totLabel) + users.map(() => str('', S.totLabel)).join('') +
    Array(5).fill(str('', S.totLabel)).join('') +
    (has ? fx(`SUBTOTAL(109,${rng(cValor)})`, r2(stats.valor), S.totMoney) : num(0, S.totMoney)) + str('', S.totLabel), 22))

  const cf = has
    ? cfRule(`${cEst}${FIRST_ROW}:${cEst}${last}`, cfCell(DXF.con, 1, 'equal', '"CUADRA"') + cfCell(DXF.bajo, 2, 'equal', '"SOBRA"') + cfCell(DXF.sin, 3, 'equal', '"FALTA"')) +
      cfRule(`${cDif}${FIRST_ROW}:${cDif}${last} ${cValor}${FIRST_ROW}:${cValor}${last}`, cfCell(DXF.negativo, 4, 'lessThan', '0') + cfCell(DXF.positivo, 5, 'greaterThan', '0')) +
      cfZebra(`A${FIRST_ROW}:${LAST}${last}`)
    : ''
  push(sheetClose({ autoFilter: has ? `A${HEADER_ROW}:${LAST}${last}` : null, merges: headerMerges(LAST), cf, footer: `${meta.empresa} · Conteo de inventario` }))
  return { stats, LAST }
}

function writePersonas (push, { stats }, users, meta) {
  const cols = [{ w: 30 }, { w: 14 }, { w: 14 }, { w: 16 }, { w: 14 }]
  const LAST = 'E'
  const merges = headerMerges(LAST)
  let r = 6, xml = headerRows(meta, 'RESUMEN DE LA REVISIÓN') + row(r++, '')
  const section = title => { merges.push(`A${r}:${LAST}${r}`); xml += row(r++, str(title, S.section) + Array(4).fill(empty(S.section)).join(''), 20) }
  const kpi = (label, cell) => { merges.push(`B${r}:C${r}`); xml += row(r++, str(label, S.kpiLabel) + cell + empty(S.kpiLabel), 18) }

  section('INDICADORES')
  kpi('Productos revisados', num(stats.revisados, S.kpiInt))
  if (meta.totalProductos) kpi('Productos del catálogo', num(meta.totalProductos, S.kpiInt))
  kpi('Cuadran con el sistema', num(stats.cuadran, S.kpiInt))
  kpi('Con sobrante', num(stats.sobrantes, S.kpiInt))
  kpi('Con faltante', num(stats.faltantes, S.kpiInt))
  kpi('Valor neto de la diferencia', num(r2(stats.valor), S.kpiMoney))
  kpi('Personas que contaron', num(users.length, S.kpiInt))
  xml += row(r++, '')

  section('QUIÉN CONTÓ')
  xml += row(r++, ['Persona', 'Productos', '% de revisados', 'Cantidad contada', 'Participación'].map(h => str(h, S.head)).join(''), 28)
  const start = r
  const total = users.reduce((s, u) => s + u.cantidad, 0)
  for (const u of [...users].sort((a, b) => b.productos - a.productos)) {
    xml += row(r, str(u.usuario, S.text) + num(u.productos, S.num) + num(stats.revisados ? Math.round(u.productos / stats.revisados * 10000) / 10000 : 0, S.pct) +
      num(r3(u.cantidad), S.qty) + num(total ? Math.round(u.cantidad / total * 10000) / 10000 : 0, S.pct))
    r++
  }
  const end = r - 1
  xml += row(r, str('TOTAL', S.totLabel) + (users.length ? fx(`SUM(B${start}:B${end})`, users.reduce((s, u) => s + u.productos, 0), S.totInt) : num(0, S.totInt)) +
    str('', S.totLabel) + (users.length ? fx(`SUM(D${start}:D${end})`, r3(total), S.totQty) : num(0, S.totQty)) + num(total ? 1 : 0, S.totPct), 20)
  r++
  merges.push(`A${r + 1}:${LAST}${r + 1}`)
  xml += row(r++, '') + row(r, str('Un producto contado por varias personas suma en cada una: por eso los productos por persona pueden pasar el total revisado.', S.meta))

  push(sheetOpen({ cols, tab: 'FF2E7D32' }) + xml + sheetClose({ merges, cf: users.length ? cfZebra(`A${start}:E${end}`, 1) : '', footer: `${meta.empresa} · Resumen de la revisión` }))
}

/**
 * meta: { empresa, datos, filtros, usuario, fecha, numero, totalProductos }
 * users: [{ user_id, usuario, productos, cantidad }] (de exportar-datos)
 */
export function buildInventarioExcel (rows, usersRaw, meta, { onProgress = () => {}, signal } = {}) {
  const users = usersRaw.map(u => ({ ...u, key: userKey(u.user_id, u.usuario), cantidad: Number(u.cantidad || 0) }))
    .sort((a, b) => a.usuario.localeCompare(b.usuario))
  const m = { ...meta, extra: `${rows.length.toLocaleString('es-BO')} productos revisados` }
  const lastCol = col(11 + users.length)
  const lastData = FIRST_ROW + Math.max(rows.length, 1) - 1
  return buildWorkbook({
    title: `Inventario ${meta.numero}`, creator: meta.usuario, app: meta.empresa,
    sheets: [
      { name: 'Conteo', filter: rows.length ? `$A$${HEADER_ROW}:$${lastCol}$${lastData}` : null, printTitles: `$${HEADER_ROW}:$${HEADER_ROW}`, write: push => writeConteo(push, rows, users, m, onProgress, signal) },
      { name: 'Resumen', write: (push, result) => writePersonas(push, result, users, m) }
    ]
  })
}
