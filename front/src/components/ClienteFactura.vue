<template>
  <div class="q-mb-sm">
    <div class="row q-col-gutter-xs">
      <q-select
        v-model="cliente.tipo_documento"
        :options="tipos"
        emit-value
        map-options
        dense
        outlined
        options-dense
        label="Doc."
        class="col-3"
        @update:model-value="onTipoChange"
      />
      <q-input
        ref="numeroInput"
        v-model="cliente.numero_documento"
        dense
        outlined
        :autofocus="dialogo"
        :label="labelDocumento"
        :inputmode="soloNumeros ? 'numeric' : 'text'"
        class="col"
        :loading="buscando || verificandoNit"
        @keydown="onKeydown"
        @update:model-value="onNumeroInput"
        @blur="verificarNit"
      >
        <template #append v-if="cliente.tipo_documento === 'NIT' && documento">
          <q-icon
            v-if="nitValido === true"
            name="check_circle"
            color="positive"
            size="18px"
          >
            <q-tooltip>NIT activo en el padrón del SIN</q-tooltip>
          </q-icon>
          <q-icon
            v-else-if="nitValido === false"
            name="warning"
            color="negative"
            size="18px"
          >
            <q-tooltip>NIT no figura en el padrón (requiere código de excepción 1)</q-tooltip>
          </q-icon>
        </template>
        <q-menu v-model="menu" no-focus no-refocus fit anchor="bottom left" self="top left">
          <q-list dense style="min-width:260px">
            <q-item v-for="c in sugerencias" :key="c.id" clickable v-close-popup @click="elegir(c)">
              <q-item-section>
                <q-item-label>{{c.nombre}}</q-item-label>
                <q-item-label caption>{{c.tipo_documento}} {{c.numero_documento}}{{c.complemento?'-'+c.complemento:''}}</q-item-label>
              </q-item-section>
            </q-item>
          </q-list>
        </q-menu>
      </q-input>
      <q-input
        v-if="cliente.tipo_documento==='CI'&&documento"
        v-model="cliente.complemento"
        v-uppercase
        dense
        outlined
        label="Compl."
        maxlength="5"
        class="col-3"
      />
      <q-input
        v-if="documento"
        v-model="cliente.cliente_nombre"
        dense
        outlined
        label="Nombre / razón social"
        class="col-12"
        :disable="!documento"
      />
      <q-input
        v-if="documento"
        v-model="cliente.cliente_email"
        dense
        outlined
        type="email"
        label="Correo (opcional)"
        class="col-12"
      />

      <!-- Control de excepción para NIT -->
      <div v-if="cliente.tipo_documento === 'NIT' && documento" class="col-12 q-pt-xs">
        <div class="row items-center justify-between bg-grey-1 q-pa-xs rounded-borders" style="border: 1px dashed #cfd8dc">
          <q-checkbox
            v-model="cliente.codigo_excepcion"
            dense
            size="sm"
            color="orange-9"
            label="Excepción de NIT (enviar código 1 al SIN)"
          />
          <span v-if="nitValido === false" class="text-caption text-negative text-weight-bold">
            <q-icon name="warning"/> No figura en padrón
          </span>
          <span v-else-if="nitValido === true" class="text-caption text-positive">
            <q-icon name="verified"/> Activo en padrón
          </span>
        </div>
        <div v-if="cliente.codigo_excepcion" class="text-caption text-orange-9 q-mt-xs q-px-xs">
          <q-icon name="info" size="14px"/> Se enviará con <b>&lt;codigoExcepcion&gt;1&lt;/codigoExcepcion&gt;</b> en el XML.
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, getCurrentInstance, ref } from 'vue'
import { clienteVacio } from '../stores/carritos-store'

// `offline`: la caja sin conexión no busca clientes ni verifica NIT, sólo toma los datos.
// Sólo datos del comprador: la pantalla nunca habla de otro comprobante. Con documento se factura;
// sin documento la página registra la venta sin factura, sin decirlo.
const props = defineProps({ offline: { type: Boolean, default: false }, dialogo: { type: Boolean, default: false } })
const cliente = defineModel('cliente', { type: Object, required: true })
const { proxy } = getCurrentInstance()
const tipos = [
  { label: 'CI', value: 'CI' },
  { label: 'NIT', value: 'NIT' },
  { label: 'CEX', value: 'CEX' },
  { label: 'PAS', value: 'PAS' },
  { label: 'OD', value: 'OD' }
]
const sugerencias = ref([]), menu = ref(false), buscando = ref(false), verificandoNit = ref(false), nitValido = ref(null)
const documento = computed(() => String(cliente.value.numero_documento || '').trim())
const soloNumeros = computed(() => ['CI', 'NIT'].includes(cliente.value.tipo_documento))
const labelDocumento = computed(() => {
  if (cliente.value.tipo_documento === 'NIT') return 'NIT'
  if (cliente.value.tipo_documento === 'CI') return 'CI'
  if (cliente.value.tipo_documento === 'PAS') return 'Pasaporte'
  if (cliente.value.tipo_documento === 'CEX') return 'CEX (Extranjero)'
  return 'Doc. ' + (cliente.value.tipo_documento || 'NIT / CI')
})

let pedido = 0, espera = null, debounceNit = null

function onKeydown (e) {
  // Permitir teclas especiales y de navegación (Backspace, Tab, Enter, Arrows, Delete, etc.)
  if (e.key && e.key.length > 1) return
  // Permitir combinaciones con Ctrl/Cmd/Alt (copiar, pegar, seleccionar todo, deshacer)
  if (e.ctrlKey || e.metaKey || e.altKey) return

  if (soloNumeros.value) {
    if (!/^\d$/.test(e.key)) {
      e.preventDefault()
    }
  } else {
    // Alfanumérico para PAS (Pasaporte), CEX (Extranjero) y OD (Otro Documento)
    if (!/^[a-zA-Z0-9]$/.test(e.key)) {
      e.preventDefault()
    }
  }
}

function onNumeroInput (valor) {
  const str = String(valor ?? '')
  let limpio = ''
  if (soloNumeros.value) {
    limpio = str.replace(/\D/g, '')
  } else {
    limpio = str.replace(/[^a-zA-Z0-9]/g, '').toUpperCase()
  }
  if (limpio !== str) {
    cliente.value.numero_documento = limpio
  }
  buscar(limpio)

  // Si es NIT, verificar automáticamente con el padrón del SIN
  clearTimeout(debounceNit)
  if (cliente.value.tipo_documento === 'NIT' && limpio.length >= 5) {
    debounceNit = setTimeout(() => {
      verificarNit()
    }, 500)
  } else if (cliente.value.tipo_documento === 'NIT') {
    nitValido.value = null
  }
}

function onTipoChange (nuevoTipo) {
  nitValido.value = null
  if (nuevoTipo !== 'NIT') {
    cliente.value.codigo_excepcion = false
  }
  if (nuevoTipo !== 'CI') {
    cliente.value.complemento = ''
  }
  const str = String(cliente.value.numero_documento || '')
  if (str) {
    const limpio = ['CI', 'NIT'].includes(nuevoTipo)
      ? str.replace(/\D/g, '')
      : str.replace(/[^a-zA-Z0-9]/g, '').toUpperCase()
    cliente.value.numero_documento = limpio
    buscar(limpio)
    if (nuevoTipo === 'NIT' && limpio.length >= 5) {
      verificarNit()
    }
  }
}

function buscar (valor) {
  clearTimeout(espera)
  const q = String(valor || '').trim()
  if (props.offline || q.length < 3) { menu.value = false; return }
  espera = setTimeout(async () => {
    const actual = ++pedido
    buscando.value = true
    try {
      const { data } = await proxy.$axios.get('/clientes-buscar', { params: { q } })
      if (actual !== pedido) return
      sugerencias.value = data
      const exacto = data.find(c => c.numero_documento === q && c.tipo_documento === cliente.value.tipo_documento)
      if (exacto && !cliente.value.cliente_nombre) elegir(exacto, false)
      menu.value = data.length > 0
    } catch { menu.value = false } finally { buscando.value = false }
  }, 300)
}

function elegir (c, cerrar = true) {
  const esNum = ['CI', 'NIT'].includes(c.tipo_documento)
  const numLimpio = esNum
    ? String(c.numero_documento || '').replace(/\D/g, '')
    : String(c.numero_documento || '').replace(/[^a-zA-Z0-9]/g, '').toUpperCase()

  Object.assign(cliente.value, {
    tipo_documento: c.tipo_documento,
    numero_documento: numLimpio,
    complemento: c.complemento || '',
    cliente_nombre: c.nombre,
    cliente_email: c.email || '',
    codigo_excepcion: Boolean(cliente.value.codigo_excepcion)
  })
  if (c.tipo_documento === 'NIT' && numLimpio.length >= 5) {
    verificarNit()
  }
  if (cerrar) menu.value = false
}

async function verificarNit () {
  if (props.offline || cliente.value.tipo_documento !== 'NIT' || !documento.value) return
  verificandoNit.value = true
  try {
    const res = await proxy.$axios.get('/clientes-verificar-nit', { params: { nit: documento.value } })
    nitValido.value = res.data.valido
    // Si el padrón confirma que el NIT no existe, marcar excepción 1 automáticamente
    if (res.data.valido === false) {
      cliente.value.codigo_excepcion = true
    }
  } catch {
    nitValido.value = null
  } finally {
    verificandoNit.value = false
  }
}

function limpiar () { Object.assign(cliente.value, clienteVacio()); nitValido.value = null }
</script>
