<template>
  <q-dialog v-model="state.open" persistent>
    <q-card style="width:460px;max-width:95vw">
      <q-card-section class="row items-center no-wrap q-pb-sm">
        <q-avatar :color="color" text-color="white" :icon="state.error ? 'error' : state.fase === 'listo' ? 'check' : 'table_view'" size="40px" />
        <div class="q-ml-md">
          <div class="text-subtitle1 text-weight-bold">{{ state.titulo }}</div>
          <div class="text-caption text-grey-7">{{ faseLabel }}</div>
        </div>
      </q-card-section>
      <q-card-section class="q-pt-none">
        <q-linear-progress :value="percent / 100" size="22px" rounded :color="color" :indeterminate="state.fase === 'conteo'" track-color="grey-3" class="q-mb-sm">
          <div class="absolute-full flex flex-center"><q-badge color="white" :text-color="color" :label="percent.toFixed(0) + ' %'" /></div>
        </q-linear-progress>
        <div v-if="state.error" class="text-negative text-caption">{{ state.error }}</div>
        <template v-else>
          <div class="row q-col-gutter-sm text-center q-mt-xs">
            <div class="col-4"><div class="text-caption text-grey-7">Descargados</div><div class="text-weight-bold">{{ fmtInt(state.cargados) }} / {{ fmtInt(state.total) }}</div></div>
            <div class="col-4"><div class="text-caption text-grey-7">Bloques</div><div class="text-weight-bold">{{ state.bloquesListos }} / {{ state.bloques || '-' }}</div></div>
            <div class="col-4"><div class="text-caption text-grey-7">Velocidad</div><div class="text-weight-bold">{{ fmtInt(speed) }}/s</div></div>
            <div class="col-6"><div class="text-caption text-grey-7">Tiempo transcurrido</div><div class="text-weight-bold">{{ fmtTime(state.transcurrido) }}</div></div>
            <div class="col-6"><div class="text-caption text-grey-7">Tiempo restante</div><div class="text-weight-bold">{{ state.fase === 'listo' ? '—' : fmtTime(eta) }}</div></div>
          </div>
          <div v-if="state.reintentos" class="text-caption text-orange-8 q-mt-sm"><q-icon name="sync_problem" /> {{ state.reintentos }} bloque(s) se reintentaron por fallas de conexión</div>
        </template>
      </q-card-section>
      <q-card-actions align="right">
        <q-btn v-if="state.fase !== 'listo' && !state.error" flat color="negative" label="Cancelar" no-caps @click="$emit('cancel')" />
        <q-btn v-else flat color="primary" label="Cerrar" no-caps @click="state.open = false" />
      </q-card-actions>
    </q-card>
  </q-dialog>
</template>

<script setup>
import { computed } from 'vue'
// La descarga es lo lento, por eso pesa el 90% de la barra; armar el archivo es local.
const PESO_DESCARGA = 0.9
const props = defineProps({ state: { type: Object, required: true } })
defineEmits(['cancel'])
const fmtInt = n => Number(n || 0).toLocaleString('es-BO')
const fmtTime = s => { s = Math.max(0, Math.round(s || 0)); const m = Math.floor(s / 60); return m ? `${m} min ${String(s % 60).padStart(2, '0')} s` : `${s} s` }
const color = computed(() => props.state.error ? 'negative' : props.state.fase === 'listo' ? 'positive' : 'primary')
const percent = computed(() => {
  const s = props.state
  if (s.fase === 'listo') return 100
  if (!s.total) return 0
  return (s.cargados / s.total * PESO_DESCARGA + s.generados / s.total * (1 - PESO_DESCARGA)) * 100
})
const speed = computed(() => props.state.transcurrido > 0 ? props.state.cargados / props.state.transcurrido : 0)
const eta = computed(() => { const p = percent.value; return p > 1 ? props.state.transcurrido * (100 - p) / p : 0 })
const faseLabel = computed(() => {
  const s = props.state
  if (s.error) return 'La exportación no se completó'
  return ({
    conteo: `Contando ${s.unidad}…`,
    descarga: `Descargando ${s.unidad} por partes (${fmtInt(s.bloque)} por bloque)…`,
    generando: `Generando el archivo Excel… ${fmtInt(s.generados)} de ${fmtInt(s.total)} filas`,
    listo: `Listo: ${fmtInt(s.total)} ${s.unidad} exportados`
  })[s.fase] || ''
})
</script>
