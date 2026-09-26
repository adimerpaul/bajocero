/** Importe en letras para la factura: 1234.5 → "UN MIL DOSCIENTOS TREINTA Y CUATRO 50/100". */
const UNIDADES = ['', 'UN', 'DOS', 'TRES', 'CUATRO', 'CINCO', 'SEIS', 'SIETE', 'OCHO', 'NUEVE', 'DIEZ', 'ONCE', 'DOCE', 'TRECE', 'CATORCE', 'QUINCE', 'DIECISÉIS', 'DIECISIETE', 'DIECIOCHO', 'DIECINUEVE', 'VEINTE', 'VEINTIUN', 'VEINTIDÓS', 'VEINTITRÉS', 'VEINTICUATRO', 'VEINTICINCO', 'VEINTISÉIS', 'VEINTISIETE', 'VEINTIOCHO', 'VEINTINUEVE']
const DECENAS = ['', '', '', 'TREINTA', 'CUARENTA', 'CINCUENTA', 'SESENTA', 'SETENTA', 'OCHENTA', 'NOVENTA']
const CENTENAS = ['', 'CIENTO', 'DOSCIENTOS', 'TRESCIENTOS', 'CUATROCIENTOS', 'QUINIENTOS', 'SEISCIENTOS', 'SETECIENTOS', 'OCHOCIENTOS', 'NOVECIENTOS']

function menorAMil (n) {
  if (n === 100) return 'CIEN'
  const c = Math.floor(n / 100), r = n % 100
  const resto = r < 30 ? UNIDADES[r] : DECENAS[Math.floor(r / 10)] + (r % 10 ? ' Y ' + UNIDADES[r % 10] : '')

  return [CENTENAS[c], resto].filter(Boolean).join(' ')
}

function entero (n) {
  if (n === 0) return 'CERO'
  const millones = Math.floor(n / 1e6), miles = Math.floor((n % 1e6) / 1000), resto = n % 1000

  return [
    millones ? (millones === 1 ? 'UN MILLÓN' : entero(millones) + ' MILLONES') : '',
    miles ? (miles === 1 ? 'UN MIL' : menorAMil(miles) + ' MIL') : '',
    resto ? menorAMil(resto) : '',
  ].filter(Boolean).join(' ')
}

export function numeroALetras (monto) {
  const centavos = Math.round(Number(monto || 0) * 100)

  return `${entero(Math.floor(centavos / 100))} ${String(centavos % 100).padStart(2, '0')}/100`
}
