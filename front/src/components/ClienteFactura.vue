<template>
  <div class="q-mb-sm">
    <div class="row q-col-gutter-xs">
      <q-select v-model="cliente.tipo_documento" :options="tipos" emit-value map-options dense outlined options-dense label="Doc." class="col-3" @update:model-value="nitValido=null"/>
      <q-input ref="numeroInput" v-model="cliente.numero_documento" dense outlined :autofocus="dialogo" label="NIT / CI" class="col" :loading="buscando" @update:model-value="buscar" @blur="verificarNit">
        <q-menu v-model="menu" no-focus no-refocus fit anchor="bottom left" self="top left">
          <q-list dense style="min-width:260px">
            <q-item v-for="c in sugerencias" :key="c.id" clickable v-close-popup @click="elegir(c)">
              <q-item-section><q-item-label>{{c.nombre}}</q-item-label><q-item-label caption>{{c.tipo_documento}} {{c.numero_documento}}{{c.complemento?'-'+c.complemento:''}}</q-item-label></q-item-section>
            </q-item>
          </q-list>
        </q-menu>
      </q-input>
      <q-input v-if="cliente.tipo_documento==='CI'&&documento" v-model="cliente.complemento" v-uppercase dense outlined label="Compl." maxlength="5" class="col-3"/>
      <q-input v-if="documento" v-model="cliente.cliente_nombre" dense outlined label="Nombre / razón social" class="col-12" :disable="!documento"/>
      <q-input v-if="documento" v-model="cliente.cliente_email" dense outlined type="email" label="Correo (opcional)" class="col-12"/>
      <div v-if="cliente.tipo_documento==='NIT'&&nitValido===false" class="col-12">
        <q-banner dense rounded class="bg-orange-1 text-orange-10"><q-icon name="warning" class="q-mr-xs"/>El NIT no existe en el padrón de Impuestos.
          <q-checkbox v-model="cliente.codigo_excepcion" dense size="sm" label="Facturar igual con este NIT" class="q-ml-sm"/>
        </q-banner>
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
const tipos = [{ label: 'CI', value: 'CI' }, { label: 'NIT', value: 'NIT' }, { label: 'CEX', value: 'CEX' }, { label: 'PAS', value: 'PAS' }, { label: 'OD', value: 'OD' }]
const sugerencias = ref([]), menu = ref(false), buscando = ref(false), nitValido = ref(null)
const documento = computed(() => String(cliente.value.numero_documento || '').trim())
let pedido = 0, espera = null

function buscar (valor) {
  nitValido.value = null
  cliente.value.codigo_excepcion = false
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
  Object.assign(cliente.value, { tipo_documento: c.tipo_documento, numero_documento: c.numero_documento, complemento: c.complemento || '', cliente_nombre: c.nombre, cliente_email: c.email || '' })
  if (cerrar) menu.value = false
}

async function verificarNit () {
  if (props.offline || cliente.value.tipo_documento !== 'NIT' || !documento.value) return
  try { nitValido.value = (await proxy.$axios.get('/clientes-verificar-nit', { params: { nit: documento.value } })).data.valido } catch { nitValido.value = null }
}

function limpiar () { Object.assign(cliente.value, clienteVacio()); nitValido.value = null }
</script>
