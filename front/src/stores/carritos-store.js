import { defineStore, acceptHMRUpdate } from 'pinia'
import { computed } from 'vue'

/**
 * Carritos en espera (hasta 5) para ventas y para compras.
 *
 * El cajero atiende a varias personas a la vez: deja un carrito a medias, abre otro,
 * cobra el segundo y vuelve al primero. Cada carrito guarda sus ítems y también sus
 * datos de cabecera (descuento, tipo de pago, proveedor…), así cambiar de carrito
 * devuelve la venta/compra exactamente como se dejó.
 *
 * Todo se copia a localStorage en cada cambio, de modo que recargar la página o
 * cerrar el navegador no pierde lo que ya se cobró a medias.
 */

const KEY = 'carritosBajoCero'

export const MAX_CARRITOS = 5

const MODULOS = {
  // `cliente` son los datos de la factura: sin número de documento sale a CONTROL TRIBUTARIO.
  ventas: { etiqueta: 'Carrito', nuevo: () => ({ items: [], descuento: 0, observacion: '', tipoPago: 'EFECTIVO', efectivo: 0, qr: 0, comprobante: 'FACTURA', cliente: clienteVacio() }) },
  compras: { etiqueta: 'Compra', nuevo: () => ({ items: [], proveedor: null, factura: '', comentario: '', tipoPago: 'EFECTIVO', efectivo: 0, qr: 0 }) },
}

export function clienteVacio () {
  return { tipo_documento: 'CI', numero_documento: '', complemento: '', cliente_nombre: '', cliente_email: '', codigo_excepcion: false }
}

function crearCarrito (modulo, numero) {
  return { id: `${modulo}-${Date.now()}-${numero}`, nombre: `${MODULOS[modulo].etiqueta} ${numero}`, ...MODULOS[modulo].nuevo() }
}

function moduloVacio (modulo, cantidad = 1) {
  const carritos = Array.from({ length: cantidad }, (_, index) => crearCarrito(modulo, index + 1))

  return { carritos, activo: carritos[0].id }
}

function estadoInicial () {
  const base = { ventas: moduloVacio('ventas', MAX_CARRITOS), compras: moduloVacio('compras') }

  try {
    const guardado = JSON.parse(localStorage.getItem(KEY) || 'null')

    if (!guardado) return base

    Object.keys(base).forEach(modulo => {
      const carritos = (guardado[modulo]?.carritos || []).filter(c => c?.id).slice(0, MAX_CARRITOS)

      if (!carritos.length) return
      // Se completa lo que falte por si el carrito viene de una versión anterior.
      const restaurados = carritos.map(c => ({ ...MODULOS[modulo].nuevo(), ...c, items: Array.isArray(c.items) ? c.items : [] }))
      if (modulo === 'ventas') {
        const nombres = restaurados.map(c => c.nombre)
        let numero = 1
        while (restaurados.length < MAX_CARRITOS) {
          while (nombres.includes(`${MODULOS[modulo].etiqueta} ${numero}`)) numero++
          const nuevo = crearCarrito(modulo, numero)
          restaurados.push(nuevo)
          nombres.push(nuevo.nombre)
        }
      }
      base[modulo] = { carritos: restaurados, activo: '' }
      base[modulo].activo = carritos.some(c => c.id === guardado[modulo]?.activo) ? guardado[modulo].activo : carritos[0].id
    })
  } catch {
    return base
  }

  return base
}

export const useCarritosStore = defineStore('carritos', {
  state: () => estadoInicial(),

  getters: {
    carritosDe: (state) => (modulo) => state[modulo].carritos,
    carritoActivo: (state) => (modulo) => state[modulo].carritos.find(c => c.id === state[modulo].activo) || state[modulo].carritos[0],
  },

  actions: {
    activar (modulo, id) {
      if (this[modulo].carritos.some(c => c.id === id)) this[modulo].activo = id
    },

    /** Devuelve el carrito nuevo, o null si ya se llegó al máximo. */
    agregar (modulo) {
      if (this[modulo].carritos.length >= MAX_CARRITOS) return null
      const usados = this[modulo].carritos.map(c => c.nombre)
      let numero = 1
      while (usados.includes(`${MODULOS[modulo].etiqueta} ${numero}`)) numero++
      const carrito = crearCarrito(modulo, numero)

      this[modulo].carritos.push(carrito)
      this[modulo].activo = carrito.id

      return carrito
    },

    /** Cierra el carrito; si era el último que quedaba deja uno vacío en su lugar. */
    cerrar (modulo, id) {
      this[modulo].carritos = this[modulo].carritos.filter(c => c.id !== id)
      if (!this[modulo].carritos.length) this[modulo].carritos.push(crearCarrito(modulo, 1))
      if (!this[modulo].carritos.some(c => c.id === this[modulo].activo)) this[modulo].activo = this[modulo].carritos[0].id
    },

    /** Vacía el carrito conservando su pestaña (lo que se usa después de cobrar). */
    vaciar (modulo, id) {
      const carrito = this[modulo].carritos.find(c => c.id === id)

      if (carrito) Object.assign(carrito, MODULOS[modulo].nuevo())
    },

    renombrar (modulo, id, nombre) {
      const carrito = this[modulo].carritos.find(c => c.id === id)

      if (carrito && String(nombre || '').trim()) carrito.nombre = String(nombre).trim().slice(0, 24)
    },
  },
})

let persistiendo = false
let guardado = null

/** Se escribe agrupando los cambios: escribir en cada tecla del campo cantidad no aporta nada. */
function persistir (state) {
  clearTimeout(guardado)
  guardado = setTimeout(() => {
    try {
      localStorage.setItem(KEY, JSON.stringify({ ventas: state.ventas, compras: state.compras }))
    } catch { /* localStorage lleno o bloqueado: el carrito sigue vivo en memoria */ }
  }, 300)
}

/**
 * Acceso cómodo desde las páginas: entrega el carrito activo del módulo ya reactivo
 * junto con las acciones, sin que la página tenga que repetir el nombre del módulo.
 */
export function useCarritos (modulo) {
  const store = useCarritosStore()

  if (!persistiendo) {
    persistiendo = true
    store.$subscribe((mutation, state) => persistir(state), { detached: true })
  }

  const lista = computed(() => store[modulo].carritos)
  const activoId = computed(() => store[modulo].activo)
  const carrito = computed(() => store.carritoActivo(modulo))

  return {
    store,
    lista,
    activoId,
    carrito,
    activar: id => store.activar(modulo, id),
    agregar: () => store.agregar(modulo),
    cerrar: id => store.cerrar(modulo, id),
    vaciar: (id = store[modulo].activo) => store.vaciar(modulo, id),
    renombrar: (id, nombre) => store.renombrar(modulo, id, nombre),
    campo: (nombre) => computed({ get: () => store.carritoActivo(modulo)[nombre], set: valor => { store.carritoActivo(modulo)[nombre] = valor } }),
  }
}

if (import.meta.hot) {
  import.meta.hot.accept(acceptHMRUpdate(useCarritosStore, import.meta.hot))
}
