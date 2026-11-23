import { STYLES as S, DXF, buildWorkbook, cfCell, cfRule, cfZebra, col, empty, fx, fxStr, headerMerges, headerRows, num, r2, row, sheetClose, sheetOpen, str, tick } from './xlsx.js'

/**
 * Excel de productos, pensado para catálogos de 100 000+ filas (ver addons/xlsx.js).
 *
 * Fila de datos (lo que devuelve GET /productos-exportar/datos):
 * [codigo, codigo_barras, nombre, categoria, unidad, p_compra, p_venta, p1..p5, stock]
 */

const STOCK_BAJO = 10 // mismo corte que el filtro 'bajo' del backend (0.001 – 10)
const CHUNK = 4000
const HEADER_ROW = 7
const FIRST_ROW = 8

const PRODUCT_COLS = [
  { h: 'N°', w: 7 }, { h: 'Código', w: 12 }, { h: 'Código barras', w: 16 }, { h: 'Producto', w: 42 },
  { h: 'Categoría', w: 20 }, { h: 'Unidad', w: 9 }, { h: 'P. compra', w: 11 }, { h: 'P. venta', w: 11 },
  { h: 'Precio 1', w: 10 }, { h: 'Precio 2', w: 10 }, { h: 'Precio 3', w: 10 }, { h: 'Precio 4', w: 10 }, { h: 'Precio 5', w: 10 },
  { h: 'Stock', w: 11 }, { h: 'Valor compra', w: 14 }, { h: 'Valor venta', w: 14 }, { h: 'Margen', w: 9 }, { h: 'Estado', w: 13 }
]
const LAST_COL = col(PRODUCT_COLS.length) // R
const estadoDe = stock => stock <= 0 ? 'SIN STOCK' : stock <= STOCK_BAJO ? 'STOCK BAJO' : 'CON STOCK'

/**
 * Hoja "Productos". Recorre las filas por bloques, acumula los totales para la hoja de
 * resumen y avisa del avance con onProgress(filasHechas).
 */
async function writeProductos (push, rows, meta, onProgress, signal) {
  const last = FIRST_ROW + rows.length - 1
  const maxName = rows.reduce((m, r) => Math.max(m, String(r[2] ?? '').length), 20)
  const cols = PRODUCT_COLS.map((c, i) => i === 3 ? { ...c, w: Math.min(Math.max(maxName * 0.95, 24), 60) } : c)
  const stats = { productos: rows.length, con: 0, sin: 0, bajo: 0, compra: 0, venta: 0, categorias: new Map(), unidades: new Map() }

  push(sheetOpen({ cols, freeze: { x: 4, y: HEADER_ROW }, tab: 'FF1565C0' }) + headerRows(meta, 'REPORTE DE PRODUCTOS') +
    row(6, '') + row(HEADER_ROW, PRODUCT_COLS.map(c => str(c.h, S.head)).join(''), 30))

  for (let start = 0; start < rows.length; start += CHUNK) {
    if (signal?.aborted) throw new DOMException('Exportación cancelada', 'AbortError')
    let xml = ''
    const end = Math.min(start + CHUNK, rows.length)
    for (let i = start; i < end; i++) {
      const p = rows[i], r = FIRST_ROW + i
      const compra = Number(p[5]) || 0, venta = Number(p[6]) || 0, stock = Number(p[12]) || 0
      const vCompra = r2(stock * compra), vVenta = r2(stock * venta)
      const cat = p[3] || 'SIN CATEGORÍA', uni = p[4] || '-'
      stats.compra += vCompra; stats.venta += vVenta
      if (stock > 0) stats.con++; else stats.sin++
      if (stock > 0 && stock <= STOCK_BAJO) stats.bajo++
      const c = stats.categorias.get(cat) || { productos: 0, con: 0, sin: 0, compra: 0, venta: 0 }
      c.productos++; c.compra += vCompra; c.venta += vVenta; if (stock > 0) c.con++; else c.sin++
      stats.categorias.set(cat, c)
      const u = stats.unidades.get(uni) || { productos: 0, stock: 0, compra: 0, venta: 0 }
      u.productos++; u.stock += stock; u.compra += vCompra; u.venta += vVenta
      stats.unidades.set(uni, u)

      xml += `<row r="${r}">` + num(i + 1, S.num) + str(p[0], S.code) + (p[1] ? str(p[1], S.code) : empty(S.code)) +
        str(p[2], S.text) + str(cat, S.text) + str(uni, S.center) +
        num(compra, S.money) + num(venta, S.money) + num(p[7], S.money) + num(p[8], S.money) + num(p[9], S.money) +
        num(p[10], S.money) + num(p[11], S.money) + num(stock, S.qty) + num(vCompra, S.money) + num(vVenta, S.money) +
        (venta > 0 ? num(Math.round((venta - compra) / venta * 10000) / 10000, S.pct) : empty(S.pct)) +
        str(estadoDe(stock), S.estado) + '</row>'
    }
    push(xml)
    onProgress(end)
    await tick()
  }

  // Totales con SUBTOTAL: respetan el autofiltro, así al filtrar una categoría se recalculan solos.
  const t = last + 1, rng = c => `${c}${FIRST_ROW}:${c}${last}`
  const has = rows.length > 0
  push(row(t, str('', S.totLabel) + str('', S.totLabel) + str('', S.totLabel) +
    (has ? fxStr(`"TOTAL: "&SUBTOTAL(103,${rng('D')})&" productos"`, `TOTAL: ${rows.length} productos`, S.totLabel) : str('TOTAL: 0 productos', S.totLabel)) +
    Array(10).fill(str('', S.totLabel)).join('') + // E … N (el stock mezcla unidades: no se suma)
    (has ? fx(`SUBTOTAL(109,${rng('O')})`, r2(stats.compra), S.totMoney) + fx(`SUBTOTAL(109,${rng('P')})`, r2(stats.venta), S.totMoney) : num(0, S.totMoney) + num(0, S.totMoney)) +
    (has ? fx(`IFERROR((P${t}-O${t})/P${t},0)`, stats.venta ? (stats.venta - stats.compra) / stats.venta : 0, S.totPct) : num(0, S.totPct)) +
    str('', S.totLabel), 22))

  const cf = has
    ? cfRule(`R${FIRST_ROW}:R${last}`, cfCell(DXF.sin, 1, 'equal', '"SIN STOCK"') + cfCell(DXF.bajo, 2, 'equal', '"STOCK BAJO"') + cfCell(DXF.con, 3, 'equal', '"CON STOCK"')) +
      cfRule(`N${FIRST_ROW}:N${last}`, cfCell(DXF.negativo, 4, 'lessThanOrEqual', '0')) +
      cfRule(`Q${FIRST_ROW}:Q${last}`, cfCell(DXF.negativo, 5, 'lessThan', '0')) +
      cfZebra(`A${FIRST_ROW}:${LAST_COL}${last}`)
    : ''
  push(sheetClose({ autoFilter: has ? `A${HEADER_ROW}:${LAST_COL}${last}` : null, merges: headerMerges(LAST_COL), cf, footer: `${meta.empresa} · Reporte de productos` }))
  return stats
}

/** Hoja "Resumen": indicadores generales, por categoría y por unidad. */
function writeResumen (push, stats, meta) {
  const cols = [{ w: 34 }, { w: 12 }, { w: 12 }, { w: 12 }, { w: 16 }, { w: 16 }, { w: 16 }, { w: 12 }]
  const LAST = 'H'
  const merges = headerMerges(LAST)
  const margen = stats.venta ? (stats.venta - stats.compra) / stats.venta : 0
  let r = 6, xml = headerRows(meta, 'RESUMEN DEL INVENTARIO') + row(r++, '')

  const section = title => { merges.push(`A${r}:${LAST}${r}`); xml += row(r++, str(title, S.section) + Array(7).fill(empty(S.section)).join(''), 20) }
  const kpi = (label, cell) => { merges.push(`B${r}:C${r}`); xml += row(r++, str(label, S.kpiLabel) + cell + empty(S.kpiLabel), 18) }

  section('INDICADORES GENERALES')
  kpi('Productos en el reporte', num(stats.productos, S.kpiInt))
  kpi('Con stock', num(stats.con, S.kpiInt))
  kpi(`Stock bajo (≤ ${STOCK_BAJO})`, num(stats.bajo, S.kpiInt))
  kpi('Sin stock', num(stats.sin, S.kpiInt))
  kpi('Valor del inventario (costo)', num(r2(stats.compra), S.kpiMoney))
  kpi('Valor del inventario (venta)', num(r2(stats.venta), S.kpiMoney))
  kpi('Ganancia potencial', num(r2(stats.venta - stats.compra), S.kpiMoney))
  kpi('Margen sobre venta', num(Math.round(margen * 10000) / 10000, S.kpiPct))
  xml += row(r++, '')

  section('POR CATEGORÍA (ordenado por valor de inventario)')
  xml += row(r++, ['Categoría', 'Productos', 'Con stock', 'Sin stock', 'Valor costo', 'Valor venta', 'Ganancia', '% inventario'].map(h => str(h, S.head)).join(''), 28)
  const catStart = r
  const categorias = [...stats.categorias.entries()].sort((a, b) => b[1].compra - a[1].compra || a[0].localeCompare(b[0]))
  for (const [nombre, c] of categorias) {
    xml += row(r, str(nombre, S.text) + num(c.productos, S.num) + num(c.con, S.num) + num(c.sin, S.num) + num(r2(c.compra), S.money) + num(r2(c.venta), S.money) +
      fx(`F${r}-E${r}`, r2(c.venta - c.compra), S.money) + fx(`IFERROR(E${r}/E$${catStart + categorias.length},0)`, stats.compra ? c.compra / stats.compra : 0, S.pct))
    r++
  }
  const catEnd = r - 1, sum = (c, v, s) => categorias.length ? fx(`SUM(${c}${catStart}:${c}${catEnd})`, v, s) : num(0, s)
  xml += row(r, str('TOTAL', S.totLabel) + sum('B', stats.productos, S.totInt) + sum('C', stats.con, S.totInt) + sum('D', stats.sin, S.totInt) +
    sum('E', r2(stats.compra), S.totMoney) + sum('F', r2(stats.venta), S.totMoney) + sum('G', r2(stats.venta - stats.compra), S.totMoney) + num(stats.compra ? 1 : 0, S.totPct), 20)
  r++
  xml += row(r++, '')

  section('POR UNIDAD DE MEDIDA')
  xml += row(r++, ['Unidad', 'Productos', 'Stock total', '', 'Valor costo', 'Valor venta', 'Ganancia', '% inventario'].map(h => str(h, S.head)).join(''), 28)
  const uniStart = r
  const unidades = [...stats.unidades.entries()].sort((a, b) => b[1].compra - a[1].compra)
  for (const [nombre, u] of unidades) {
    xml += row(r, str(nombre, S.text) + num(u.productos, S.num) + num(Math.round(u.stock * 1000) / 1000, S.qty) + empty(S.text) +
      num(r2(u.compra), S.money) + num(r2(u.venta), S.money) + fx(`F${r}-E${r}`, r2(u.venta - u.compra), S.money) +
      num(stats.compra ? Math.round(u.compra / stats.compra * 10000) / 10000 : 0, S.pct))
    r++
  }
  const uniEnd = r - 1, usum = (c, v, s) => unidades.length ? fx(`SUM(${c}${uniStart}:${c}${uniEnd})`, v, s) : num(0, s)
  xml += row(r, str('TOTAL', S.totLabel) + usum('B', stats.productos, S.totInt) + str('', S.totLabel) + str('', S.totLabel) +
    usum('E', r2(stats.compra), S.totMoney) + usum('F', r2(stats.venta), S.totMoney) + usum('G', r2(stats.venta - stats.compra), S.totMoney) + num(stats.compra ? 1 : 0, S.totPct), 20)

  push(sheetOpen({ cols, tab: 'FF2E7D32' }) + xml + sheetClose({ merges, cf: categorias.length ? cfZebra(`A${catStart}:H${catEnd}`, 1) : '', footer: `${meta.empresa} · Resumen del inventario` }))
}

/**
 * Construye el .xlsx y devuelve un Blob.
 * meta: { empresa, datos, filtros, usuario, fecha, total }
 * onProgress(filasProcesadas) se llama tras cada bloque.
 */
export function buildProductosExcel (rows, meta, { onProgress = () => {}, signal } = {}) {
  const m = { ...meta, filtros: `Filtros: ${meta.filtros}`, extra: `${meta.total.toLocaleString('es-BO')} productos` }
  const lastData = FIRST_ROW + Math.max(rows.length, 1) - 1
  return buildWorkbook({
    title: 'Reporte de productos', creator: meta.usuario, app: meta.empresa,
    sheets: [
      { name: 'Productos', filter: rows.length ? `$A$${HEADER_ROW}:$${LAST_COL}$${lastData}` : null, printTitles: `$${HEADER_ROW}:$${HEADER_ROW}`, write: push => writeProductos(push, rows, m, onProgress, signal) },
      { name: 'Resumen', write: (push, stats) => writeResumen(push, stats, m) }
    ]
  })
}
