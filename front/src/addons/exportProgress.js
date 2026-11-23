import { reactive } from 'vue'

/**
 * Estado y descarga por bloques de los reportes Excel grandes (productos, inventario).
 * La ventana que lo muestra es components/ExportProgressDialog.vue.
 */
export function createExportState (titulo, unidad = 'productos') {
  const state = reactive({ titulo, unidad, open: false, fase: '', total: 0, cargados: 0, generados: 0, bloques: 0, bloquesListos: 0, bloque: 0, reintentos: 0, inicio: 0, transcurrido: 0, error: '' })
  let timer = null, abort = null

  function start (bloque) {
    abort = new AbortController()
    Object.assign(state, { open: true, fase: 'conteo', total: 0, cargados: 0, generados: 0, bloques: 0, bloquesListos: 0, bloque, reintentos: 0, inicio: Date.now(), transcurrido: 0, error: '' })
    clearInterval(timer)
    timer = setInterval(() => { state.transcurrido = (Date.now() - state.inicio) / 1000 }, 250)
    return abort.signal
  }
  function stop () {
    clearInterval(timer); timer = null
    state.transcurrido = (Date.now() - state.inicio) / 1000
  }
  const cancel = () => abort?.abort()
  const dispose = () => { abort?.abort(); clearInterval(timer) }

  return { state, start, stop, cancel, dispose }
}

/**
 * Baja todas las páginas de `url` en bloques (varios en paralelo, con reintentos) y las
 * devuelve en orden. La primera respuesta trae `total`; la devuelve completa como `first`
 * por si trae más datos (p. ej. la lista de usuarios del inventario).
 */
export async function fetchInBlocks (axios, url, params, { state, signal, size = 5000, parallel = 3 }) {
  async function fetchBlock (page) {
    for (let intento = 1; ; intento++) {
      try {
        const { data } = await axios.get(url, { params: { ...params, page, per_page: size }, signal })
        return data
      } catch (e) {
        // Sólo se reintenta la falta de respuesta o un 5xx; un 403/422 o la cancelación no se arreglan reintentando.
        const status = e.response?.status
        if (signal.aborted || intento >= 3 || (status && status < 500)) throw e
        state.reintentos++
        await new Promise(resolve => setTimeout(resolve, 1000 * intento))
      }
    }
  }

  const first = await fetchBlock(1)
  state.total = first.total; state.bloques = Math.max(1, Math.ceil(first.total / size)); state.fase = 'descarga'
  const blocks = new Array(state.bloques)
  blocks[0] = first.data; state.cargados = first.data.length; state.bloquesListos = 1
  // Cada bloque se guarda en su posición, así el orden se respeta aunque lleguen desordenados.
  let next = 2
  const worker = async () => {
    while (next <= state.bloques) {
      const page = next++
      const { data } = await fetchBlock(page)
      blocks[page - 1] = data; state.cargados += data.length; state.bloquesListos++
    }
  }
  await Promise.all(Array.from({ length: Math.min(parallel, state.bloques - 1) }, worker))
  const rows = blocks.flat()
  state.total = rows.length; state.cargados = rows.length; state.fase = 'generando'
  return { rows, first }
}

/** Mensaje para el usuario según cómo falló la exportación. */
export function exportErrorMessage (e) {
  return e.response?.data?.message || (e.response ? 'El servidor rechazó la exportación' : 'Se perdió la conexión con el servidor. Vuelve a intentarlo.')
}
