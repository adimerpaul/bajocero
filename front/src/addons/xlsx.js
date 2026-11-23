import { Zip, ZipDeflate, strToU8 } from 'fflate'

/**
 * Escritor .xlsx mínimo para reportes grandes armados en el navegador.
 *
 * El libro se escribe a mano (OOXML) y se comprime en streaming con fflate: cada bloque
 * de filas se convierte en XML, se comprime y se suelta, así la memoria no crece con el
 * reporte y se puede informar el avance mientras se genera. Las celdas de texto van en
 * línea (inlineStr) para no tener que armar la tabla de cadenas compartidas, y no llevan
 * referencia `r`: van en orden, así que cada fila debe traer todas sus celdas.
 *
 * Todos los reportes comparten la misma cabecera (empresa, datos, título, filtros y quién
 * exportó en las filas 1–5) y la misma paleta de estilos (S).
 */

export const STYLES = { empresa: 1, sub: 2, titulo: 3, meta: 4, head: 5, text: 6, center: 7, money: 8, qty: 9, pct: 10, num: 11, totLabel: 12, totMoney: 13, totQty: 14, totInt: 15, section: 16, kpiLabel: 17, kpiMoney: 18, kpiInt: 19, estado: 20, code: 21, totPct: 22, kpiPct: 23, qtyBold: 24, headUser: 25, qtyUser: 26, totQtyUser: 27 }
const S = STYLES

// dxfId de formato condicional
export const DXF = { zebra: 0, sin: 1, bajo: 2, con: 3, negativo: 4, positivo: 5 }

export function col (n) { let s = ''; while (n > 0) { const m = (n - 1) % 26; s = String.fromCharCode(65 + m) + s; n = Math.floor((n - 1) / 26) } return s }
// eslint-disable-next-line no-control-regex
export const esc = v => String(v ?? '').replace(/[\u0000-\u0008\u000B\u000C\u000E-\u001F]/g, '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;')
export const str = (v, s) => `<c s="${s}" t="inlineStr"><is><t xml:space="preserve">${esc(v)}</t></is></c>`
export const num = (v, s) => { const n = Number(v); return `<c s="${s}"><v>${Number.isFinite(n) ? n : 0}</v></c>` }
export const fx = (f, v, s) => `<c s="${s}"><f>${esc(f)}</f><v>${Number.isFinite(v) ? v : 0}</v></c>`
export const fxStr = (f, v, s) => `<c s="${s}" t="str"><f>${esc(f)}</f><v>${esc(v)}</v></c>`
export const empty = s => `<c s="${s}"/>`
export const row = (r, cells, ht) => `<row r="${r}"${ht ? ` ht="${ht}" customHeight="1"` : ''}>${cells}</row>`
export const r2 = n => Math.round(n * 100) / 100
export const r3 = n => Math.round(n * 1000) / 1000
export const tick = () => new Promise(resolve => setTimeout(resolve, 0))
export const toBytes = strToU8

/** Filas 1–5 comunes a todas las hojas (empresa, datos, título, filtros, exportado). */
export function headerRows (meta, titulo) {
  return [
    row(1, str(meta.empresa, S.empresa), 24),
    row(2, str(meta.datos, S.sub)),
    row(3, str(titulo, S.titulo), 22),
    row(4, str(meta.filtros, S.meta)),
    row(5, str(`Exportado por: ${meta.usuario}  ·  ${meta.fecha}${meta.extra ? `  ·  ${meta.extra}` : ''}`, S.meta))
  ].join('')
}
export const headerMerges = last => [1, 2, 3, 4, 5].map(r => `<mergeCell ref="A${r}:${last}${r}"/>`)

export function sheetOpen ({ cols, freeze, tab }) {
  const cell = freeze ? `${col(freeze.x + 1)}${freeze.y + 1}` : ''
  const pane = freeze ? `<pane${freeze.x ? ` xSplit="${freeze.x}"` : ''} ySplit="${freeze.y}" topLeftCell="${cell}" activePane="${freeze.x ? 'bottomRight' : 'bottomLeft'}" state="frozen"/><selection pane="${freeze.x ? 'bottomRight' : 'bottomLeft'}" activeCell="${cell}" sqref="${cell}"/>` : ''
  return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' +
    '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' +
    `<sheetPr><tabColor rgb="${tab}"/><pageSetUpPr fitToPage="1"/></sheetPr>` +
    `<sheetViews><sheetView workbookViewId="0" showGridLines="0">${pane}</sheetView></sheetViews>` +
    '<sheetFormatPr defaultRowHeight="15"/>' +
    `<cols>${cols.map((c, i) => `<col min="${i + 1}" max="${i + 1}" width="${c.w}" customWidth="1"/>`).join('')}</cols>` +
    '<sheetData>'
}
export function sheetClose ({ autoFilter, merges, cf = '', footer }) {
  return '</sheetData>' +
    (autoFilter ? `<autoFilter ref="${autoFilter}"/>` : '') +
    (merges.length ? `<mergeCells count="${merges.length}">${merges.join('')}</mergeCells>` : '') +
    cf +
    '<pageMargins left="0.4" right="0.4" top="0.5" bottom="0.6" header="0.3" footer="0.3"/>' +
    '<pageSetup paperSize="9" orientation="landscape" fitToWidth="1" fitToHeight="0"/>' +
    `<headerFooter><oddFooter>&amp;L${esc(footer)}&amp;RPágina &amp;P de &amp;N</oddFooter></headerFooter>` +
    '</worksheet>'
}
export const cfRule = (sqref, rules) => `<conditionalFormatting sqref="${sqref}">${rules}</conditionalFormatting>`
export const cfZebra = (sqref, priority = 10) => cfRule(sqref, `<cfRule type="expression" dxfId="${DXF.zebra}" priority="${priority}"><formula>MOD(ROW(),2)=0</formula></cfRule>`)
export const cfCell = (dxf, priority, operator, formula) => `<cfRule type="cellIs" dxfId="${dxf}" priority="${priority}" operator="${operator}"><formula>${esc(formula)}</formula></cfRule>`

function styles () {
  const font = (o = {}) => `<font>${o.b ? '<b/>' : ''}${o.i ? '<i/>' : ''}<sz val="${o.sz || 10}"/><color rgb="${o.c || 'FF263238'}"/><name val="Calibri"/><family val="2"/></font>`
  const fill = rgb => `<fill><patternFill patternType="solid"><fgColor rgb="${rgb}"/><bgColor indexed="64"/></patternFill></fill>`
  const fonts = [
    font(), // 0
    font({ b: 1, sz: 16, c: 'FF0D3B66' }), // 1 empresa
    font({ sz: 9, c: 'FF607D8B' }), // 2 sub
    font({ b: 1, sz: 13, c: 'FFFFFFFF' }), // 3 título
    font({ i: 1, sz: 9, c: 'FF455A64' }), // 4 meta
    font({ b: 1, sz: 10, c: 'FFFFFFFF' }), // 5 encabezado
    font({ b: 1, sz: 10, c: 'FF0D3B66' }), // 6 totales
    font({ b: 1, sz: 11, c: 'FF0D3B66' }), // 7 sección
    font({ b: 1, sz: 12, c: 'FF0D3B66' }), // 8 kpi valor
    font({ sz: 10, c: 'FF90A4AE' }), // 9 N°
    font({ b: 1, sz: 9 }), // 10 estado
    font({ b: 1, sz: 10 }), // 11 negrita
    font({ b: 1, sz: 10, c: 'FF4E342E' }), // 12 encabezado usuario
    font({ sz: 10, c: 'FF4E342E' }) // 13 cantidad por usuario
  ]
  const fills = ['<fill><patternFill patternType="none"/></fill>', '<fill><patternFill patternType="gray125"/></fill>',
    fill('FF0D3B66'), // 2 azul oscuro
    fill('FF1565C0'), // 3 azul encabezado
    fill('FFE8F1FB'), // 4 azul suave
    fill('FFF5F7FA'), // 5 gris kpi
    fill('FFFFE0B2'), // 6 naranja encabezado usuario
    fill('FFFFF8EE') // 7 naranja suave
  ]
  const borders = ['<border><left/><right/><top/><bottom/><diagonal/></border>',
    '<border><left/><right/><top/><bottom style="thin"><color rgb="FFE0E6ED"/></bottom><diagonal/></border>', // 1 línea fina
    '<border><left/><right/><top style="medium"><color rgb="FF1565C0"/></top><bottom style="medium"><color rgb="FF1565C0"/></bottom><diagonal/></border>', // 2 totales
    '<border><left style="thin"><color rgb="FF0D3B66"/></left><right style="thin"><color rgb="FF0D3B66"/></right><top/><bottom/><diagonal/></border>', // 3 encabezado
    '<border><left/><right/><top/><bottom style="medium"><color rgb="FF1565C0"/></bottom><diagonal/></border>', // 4 sección
    '<border><left style="thin"><color rgb="FFFFCC80"/></left><right style="thin"><color rgb="FFFFCC80"/></right><top/><bottom style="thin"><color rgb="FFE0E6ED"/></bottom><diagonal/></border>' // 5 columna usuario
  ]
  const numFmts = { 164: '"Bs" #,##0.00', 165: '#,##0.000', 166: '0.0%', 167: '#,##0', 168: 'General;-General;"—"' }
  const xf = (f, fi, b, n, al = '') => `<xf numFmtId="${n}" fontId="${f}" fillId="${fi}" borderId="${b}" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyNumberFormat="1"${al ? ' applyAlignment="1"' : ''}>${al}</xf>`
  const al = (h, v = 'center', wrap) => `<alignment horizontal="${h}" vertical="${v}"${wrap ? ' wrapText="1"' : ''}/>`
  const xfs = [
    xf(0, 0, 0, 0), // 0
    xf(1, 0, 0, 0, al('left')), // 1 empresa
    xf(2, 0, 0, 0, al('left')), // 2 sub
    xf(3, 2, 0, 0, al('center')), // 3 título
    xf(4, 0, 0, 0, al('left')), // 4 meta
    xf(5, 3, 3, 0, al('center', 'center', 1)), // 5 encabezado
    xf(0, 0, 1, 49, al('left')), // 6 texto
    xf(0, 0, 1, 49, al('center')), // 7 centrado
    xf(0, 0, 1, 164, al('right')), // 8 moneda
    xf(0, 0, 1, 165, al('right')), // 9 cantidad
    xf(0, 0, 1, 166, al('right')), // 10 porcentaje
    xf(9, 0, 1, 167, al('center')), // 11 N° / enteros
    xf(6, 4, 2, 0, al('left')), // 12 total etiqueta
    xf(6, 4, 2, 164, al('right')), // 13 total moneda
    xf(6, 4, 2, 165, al('right')), // 14 total cantidad
    xf(6, 4, 2, 167, al('center')), // 15 total entero
    xf(7, 0, 4, 0, al('left')), // 16 sección
    xf(0, 5, 1, 0, al('left')), // 17 kpi etiqueta
    xf(8, 5, 1, 164, al('right')), // 18 kpi moneda
    xf(8, 5, 1, 167, al('right')), // 19 kpi entero
    xf(10, 0, 1, 0, al('center')), // 20 estado
    xf(0, 0, 1, 49, al('left')), // 21 código (texto: no pasa a notación científica)
    xf(6, 4, 2, 166, al('right')), // 22 total porcentaje
    xf(8, 5, 1, 166, al('right')), // 23 kpi porcentaje
    xf(11, 0, 1, 165, al('right')), // 24 cantidad en negrita
    xf(12, 6, 3, 0, al('center', 'center', 1)), // 25 encabezado de usuario
    xf(13, 7, 5, 168, al('right')), // 26 cantidad de un usuario (vacío = —)
    xf(6, 6, 2, 165, al('right')) // 27 total por usuario
  ]
  const dxf = (fontColor, bg, bold) => `<dxf><font>${bold ? '<b/>' : ''}${fontColor ? `<color rgb="${fontColor}"/>` : ''}</font>${bg ? `<fill><patternFill patternType="solid"><bgColor rgb="${bg}"/></patternFill></fill>` : ''}</dxf>`
  const dxfs = [
    dxf(null, 'FFF7F9FC'), // 0 cebra
    dxf('FFC62828', 'FFFDECEA', 1), // 1 rojo (sin stock / faltante)
    dxf('FFE65100', 'FFFFF4E5', 1), // 2 naranja (stock bajo)
    dxf('FF2E7D32', 'FFEAF5EA', 1), // 3 verde (con stock / cuadra)
    dxf('FFC62828', null, 1), // 4 número negativo
    dxf('FF2E7D32', null, 1) // 5 número positivo
  ]
  return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' +
    `<numFmts count="${Object.keys(numFmts).length}">${Object.entries(numFmts).map(([id, code]) => `<numFmt numFmtId="${id}" formatCode="${esc(code)}"/>`).join('')}</numFmts>` +
    `<fonts count="${fonts.length}">${fonts.join('')}</fonts><fills count="${fills.length}">${fills.join('')}</fills><borders count="${borders.length}">${borders.join('')}</borders>` +
    '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>' +
    `<cellXfs count="${xfs.length}">${xfs.join('')}</cellXfs>` +
    '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>' +
    `<dxfs count="${dxfs.length}">${dxfs.join('')}</dxfs></styleSheet>`
}

function addFile (zip, name, content) {
  const f = new ZipDeflate(name, { level: 6 })
  zip.add(f)
  if (content !== undefined) f.push(strToU8(content), true)
  return f
}

/**
 * Arma el libro y devuelve un Blob.
 * sheets: [{ name, filter?: '$A$7:$R$100', printTitles?: '$7:$7', write: async push => resultado }]
 * `push(xml)` agrega texto a la hoja; cada hoja se escribe en orden y lo que devuelve
 * `write` se pasa a la siguiente como segundo argumento (p. ej. los totales para el resumen).
 */
export async function buildWorkbook ({ title, creator, app, sheets }) {
  const parts = []
  let zipError = null
  const zip = new Zip((err, chunk) => { if (err) zipError = err; else parts.push(chunk) })
  const iso = new Date().toISOString().replace(/\.\d+Z$/, 'Z')
  const ws = (i, tag) => `<Override PartName="/xl/worksheets/sheet${i + 1}.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>${tag}`

  addFile(zip, '[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' +
    '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>' +
    '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>' +
    sheets.map((_, i) => ws(i, '')).join('') +
    '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>' +
    '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>' +
    '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/></Types>')
  addFile(zip, '_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' +
    '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>' +
    '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>' +
    '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/></Relationships>')
  addFile(zip, 'docProps/core.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">' +
    `<dc:title>${esc(title)}</dc:title><dc:creator>${esc(creator)}</dc:creator><dcterms:created xsi:type="dcterms:W3CDTF">${iso}</dcterms:created></cp:coreProperties>`)
  addFile(zip, 'docProps/app.xml', `<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties"><Application>${esc(app)}</Application></Properties>`)
  addFile(zip, 'xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' +
    sheets.map((_, i) => `<Relationship Id="rId${i + 1}" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet${i + 1}.xml"/>`).join('') +
    `<Relationship Id="rId${sheets.length + 1}" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>`)
  const names = sheets.flatMap((sh, i) => [
    sh.filter ? `<definedName name="_xlnm._FilterDatabase" localSheetId="${i}" hidden="1">'${sh.name}'!${sh.filter}</definedName>` : '',
    sh.printTitles ? `<definedName name="_xlnm.Print_Titles" localSheetId="${i}">'${sh.name}'!${sh.printTitles}</definedName>` : ''
  ]).join('')
  addFile(zip, 'xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' +
    `<bookViews><workbookView activeTab="0"/></bookViews><sheets>${sheets.map((sh, i) => `<sheet name="${esc(sh.name)}" sheetId="${i + 1}" r:id="rId${i + 1}"/>`).join('')}</sheets>` +
    (names ? `<definedNames>${names}</definedNames>` : '') +
    '<calcPr calcId="191029" fullCalcOnLoad="1"/></workbook>')
  addFile(zip, 'xl/styles.xml', styles())

  let previous
  for (let i = 0; i < sheets.length; i++) {
    const file = addFile(zip, `xl/worksheets/sheet${i + 1}.xml`)
    previous = await sheets[i].write(xml => file.push(strToU8(xml)), previous)
    file.push(new Uint8Array(0), true)
  }
  zip.end()
  if (zipError) throw zipError

  return new Blob(parts, { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' })
}

/** Descarga un Blob con el nombre indicado. */
export function saveBlob (blob, name) {
  const url = URL.createObjectURL(blob), a = document.createElement('a')
  a.href = url; a.download = name; a.click(); setTimeout(() => URL.revokeObjectURL(url), 2000)
}

/** Sello de fecha para nombres de archivo: 20261123_1810 */
export function fileStamp (date = new Date()) {
  const pad = n => String(n).padStart(2, '0')
  return `${date.getFullYear()}${pad(date.getMonth() + 1)}${pad(date.getDate())}_${pad(date.getHours())}${pad(date.getMinutes())}`
}
