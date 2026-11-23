<template>
  <q-page class="q-pa-sm">
    <div class="row items-center q-mb-sm q-col-gutter-sm">
      <div class="col-12 col-md"><div class="text-subtitle1 text-weight-bold">{{expired?'Productos vencidos':'Productos por vencer'}}</div><div class="text-caption text-grey-7"><template v-if="inventory">Según el último inventario aplicado <b>{{inventory.numero}}</b><span v-if="inventory.descripcion"> · {{inventory.descripcion}}</span> · {{dateTime(inventory.fecha_aplicado)}}</template><template v-else-if="!loading">No hay ningún inventario aplicado todavía</template></div></div>
      <div class="col-12 col-md-auto row q-gutter-xs items-center">
        <q-input v-model="search" dense outlined clearable debounce="300" placeholder="Buscar producto, código o lote" style="width:240px" @update:model-value="load"><template #prepend><q-icon name="search"/></template></q-input>
        <q-input v-if="!expired" v-model.number="days" dense outlined type="number" min="1" max="3650" label="Próximos días" style="width:120px" debounce="400" @update:model-value="load"/>
        <q-select v-model="visibility" :options="visibilityOptions" emit-value map-options dense outlined label="Mostrar" style="width:130px" @update:model-value="load"/>
        <q-btn dense unelevated no-caps color="green-8" icon="table_view" label="Excel" :loading="exporting" :disable="!rows.length" @click="exportExcel"/>
        <q-btn dense flat round icon="refresh" :loading="loading" @click="load"/>
      </div>
    </div>
    <q-card flat bordered><q-table flat dense :rows="rows" :columns="columns" row-key="key" :loading="loading" :pagination="{rowsPerPage:50}" :rows-per-page-options="[25,50,100,0]" no-data-label="Sin productos para mostrar">
      <template #body="p"><q-tr :props="p" :class="p.row.oculto?'row-hidden':''">
        <q-td key="foto" :props="p"><div class="thumb"><img v-if="p.row.foto" :src="photoUrl(p.row.foto)" loading="lazy"/><q-icon v-else name="inventory_2" size="22px" color="grey-4"/></div><q-tooltip v-if="p.row.foto" anchor="center right" self="center left" class="bg-white shadow-4 q-pa-xs"><img :src="photoUrl(p.row.foto)" style="width:180px;height:180px;object-fit:contain"/></q-tooltip></q-td>
        <q-td key="producto" :props="p"><b>{{p.row.nombre}}</b><div class="text-caption text-grey-7">{{p.row.codigo}}<span v-if="p.row.categoria"> · {{p.row.categoria}}</span></div></q-td>
        <q-td key="lote" :props="p">{{p.row.lote||'—'}}</q-td>
        <q-td key="cantidad" :props="p">{{qty(p.row.cantidad,p.row.unidad)}} {{p.row.unidad}}</q-td>
        <q-td key="fecha" :props="p">{{date(p.row.fecha_vencimiento)}}</q-td>
        <q-td key="dias" :props="p"><q-badge :color="p.row.oculto?'grey-6':p.row.dias<0?'negative':p.row.dias<=7?'orange':'warning'" :label="p.row.dias<0?`${Math.abs(p.row.dias)} días vencido`:p.row.dias===0?'Vence hoy':`${p.row.dias} días`"/></q-td>
        <q-td key="acciones" :props="p">
          <q-chip v-if="p.row.riesgo_id" dense square clickable color="orange-2" text-color="orange-10" icon="warning" label="En riesgo" class="q-ml-none" @click="$router.push('/productos/en-riesgo')"><q-tooltip>Agregado por {{p.row.riesgo_por}} · ver productos en riesgo</q-tooltip></q-chip>
          <q-btn v-else-if="canRisk" dense flat no-caps size="sm" color="orange-9" icon="warning_amber" label="En riesgo" :loading="p.row._risk" @click="addRisk(p.row)"><q-tooltip>Agregar a productos en riesgo (no mueve stock)</q-tooltip></q-btn>
          <q-btn v-if="!p.row.oculto" dense flat no-caps size="sm" color="grey-8" icon="south" label="Quitar" :loading="p.row._saving" @click="toggle(p.row,true)"><q-tooltip>Lo manda al final de la lista sin borrarlo</q-tooltip></q-btn>
          <template v-else><q-btn dense flat no-caps size="sm" color="primary" icon="restore" label="Habilitar" :loading="p.row._saving" @click="toggle(p.row,false)"/><div class="text-caption text-grey-6">Quitado por {{p.row.oculto_por||'—'}}</div></template>
        </q-td>
      </q-tr></template>
      <template #bottom-row><q-tr v-if="rows.length"><q-td colspan="7" class="text-caption text-grey-7">{{activeCount}} activo(s) · {{rows.length-activeCount}} quitado(s)</q-td></q-tr></template>
    </q-table></q-card>
  </q-page>
</template>
<script setup>
import { computed, getCurrentInstance, onMounted, ref, watch } from 'vue'
const props=defineProps({estado:{type:String,default:'por_vencer'}}),{proxy}=getCurrentInstance(),rows=ref([]),inventory=ref(null),loading=ref(false),exporting=ref(false),days=ref(30),search=ref(''),visibility=ref('todos'),expired=computed(()=>props.estado==='vencido')
const visibilityOptions=[{label:'Todos',value:'todos'},{label:'Activos',value:'activos'},{label:'Quitados',value:'quitados'}]
const columns=[{name:'foto',label:'',field:'foto',align:'center'},{name:'producto',label:'Producto',field:'nombre',align:'left',sortable:true},{name:'lote',label:'Lote',field:'lote',align:'left'},{name:'cantidad',label:'Contado',field:'cantidad',align:'right',sortable:true},{name:'fecha',label:'Vencimiento',field:'fecha_vencimiento',align:'center',sortable:true},{name:'dias',label:'Estado',field:'dias',align:'center'},{name:'acciones',label:'',field:'key',align:'center'}]
const activeCount=computed(()=>rows.value.filter(r=>!r.oculto).length)
const photoUrl=path=>`${proxy.$imgBase}/images/${path}`,qty=(v,u)=>Number(v||0).toFixed(u==='KG'?3:0),date=v=>new Date(`${String(v).slice(0,10)}T12:00:00`).toLocaleDateString('es-BO'),dateTime=v=>v?new Date(v).toLocaleString('es-BO'):''
const canRisk=computed(()=>proxy.$store.hasPermission('Gestionar Productos en Riesgo'))
// Copia el lote a "Productos en riesgo": solo un registro de seguimiento, no toca stock ni inventario.
function addRisk(row){proxy.$alert.dialogPrompt(`Agregar ${row.nombre}${row.lote?` (lote ${row.lote})`:''} a productos en riesgo. Observación (opcional):`).onOk(text=>{row._risk=true;proxy.$axios.post('/productos-riesgo',{producto_id:row.producto_id,lote:row.lote,fecha_vencimiento:row.fecha_vencimiento,cantidad:row.cantidad,origen:row.dias<0?'VENCIDO':'POR_VENCER',observacion:text||null}).then(()=>{proxy.$alert.success(`${row.nombre} agregado a productos en riesgo`);load()}).catch(e=>proxy.$alert.error(e.response?.data?.message||'No se pudo agregar')).finally(()=>row._risk=false)})}
const params=()=>({estado:props.estado,dias:days.value||30,q:search.value||'',mostrar:visibility.value})
function load(){loading.value=true;proxy.$axios.get('/vencimientos',{params:params()}).then(r=>{rows.value=r.data.data;inventory.value=r.data.inventario}).catch(e=>proxy.$alert.error(e.response?.data?.message||'No se pudo cargar')).finally(()=>loading.value=false)}
// Quitar no borra nada: marca el lote, lo manda al final y se puede volver a habilitar.
function toggle(row,hidden){row._saving=true;proxy.$axios.put(`/vencimientos/${row.tipo}/${row.id}/ocultar`,{oculto:hidden}).then(()=>{proxy.$alert.success(hidden?`${row.nombre} quitado de la lista`:`${row.nombre} habilitado`);load()}).catch(e=>proxy.$alert.error(e.response?.data?.message||'No se pudo actualizar')).finally(()=>row._saving=false)}
function exportExcel(){exporting.value=true;proxy.$axios.get('/vencimientos-exportar/excel',{params:params(),responseType:'blob'}).then(r=>{const url=URL.createObjectURL(r.data),a=document.createElement('a');a.href=url;a.download=`${expired.value?'vencidos':'por_vencer'}_${new Date().toISOString().slice(0,10)}.xlsx`;a.click();URL.revokeObjectURL(url)}).catch(()=>proxy.$alert.error('No se pudo exportar')).finally(()=>exporting.value=false)}
watch(()=>props.estado,load);onMounted(load)
</script>
<style scoped>
.thumb{width:40px;height:40px;border-radius:4px;background:#fff7f6;display:flex;align-items:center;justify-content:center;overflow:hidden}.thumb img{width:100%;height:100%;object-fit:contain}
.row-hidden{opacity:.55;background:#f5f5f5}
</style>
