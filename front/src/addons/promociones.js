const KEY = 'promocionesBajoCero'

/** Promociones activas guardadas en el dispositivo: los cupones se imprimen también sin conexión. */
export function promocionesActivas () {
  try {
    return JSON.parse(localStorage.getItem(KEY) || '[]')
  } catch {
    return []
  }
}

/** Baja las promociones activas; si no hay conexión se siguen usando las guardadas. */
export async function actualizarPromociones (axios) {
  try {
    const { data } = await axios.get('/promociones-activas')
    localStorage.setItem(KEY, JSON.stringify(data))
  } catch { /* sin red o sin permiso: queda la copia anterior */ }

  return promocionesActivas()
}

/** Promociones cuyo monto alcanza el total de la venta. */
export function promocionesPara (total) {
  return promocionesActivas().filter(p => Number(total || 0) >= Number(p.monto))
}

/** Fichas que gana la venta: una por cada `valor_ficha` Bs (30 Bs y compra 90 → 3); sin valor, una sola. */
export function fichasPara (promo, total) {
  const valor = Number(promo.valor_ficha || 0)
  return valor > 0 ? Math.max(1, Math.floor((Number(total || 0) + 0.001) / valor)) : 1
}
