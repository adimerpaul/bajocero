<template>
  <q-page class="q-pa-sm">
    <div class="row items-center q-mb-sm"><div><div class="text-subtitle1 text-weight-bold">Promociones</div><div class="text-caption text-grey-7">Sorteos por monto de compra · al cobrar (o reimprimir) una venta que llega al monto se imprimen sus fichas para el ánfora</div></div><q-space/><q-btn v-if="can('Crear Promociones')" dense unelevated color="primary" icon="add" label="Nueva promoción" no-caps @click="openForm()"/></div>
    <q-card flat bordered>
      <q-card-section class="q-pa-sm"><q-input v-model="search" dense outlined clearable placeholder="Buscar nombre o título"><template #prepend><q-icon name="search"/></template></q-input></q-card-section>
      <q-table flat dense :rows="filtered" :columns="columns" row-key="id" :loading="loading" :pagination="{rowsPerPage:20}">
        <template #body-cell-titulo="p"><q-td :props="p"><div class="text-weight-bold">{{p.row.titulo}}</div><div class="text-caption">{{p.row.subtitulo||''}}</div></q-td></template>
        <template #body-cell-monto="p"><q-td :props="p">Bs {{Number(p.value).toFixed(2)}}</q-td></template>
        <template #body-cell-valor_ficha="p"><q-td :props="p">{{p.value?`1 ficha c/ Bs ${Number(p.value).toFixed(2)}`:'1 ficha'}}</q-td></template>
        <template #body-cell-estado="p"><q-td :props="p"><q-toggle v-if="can('Editar Promociones')" :model-value="p.row.estado" dense color="positive" @update:model-value="v=>toggle(p.row,v)"/><q-badge v-else :color="p.row.estado?'positive':'grey'" :label="p.row.estado?'ACTIVA':'INACTIVA'"/></q-td></template>
        <template #body-cell-actions="p"><q-td :props="p"><q-btn v-if="can('Editar Promociones')" dense flat round size="sm" icon="edit" color="primary" @click="openForm(p.row)"/><q-btn v-if="can('Eliminar Promociones')" dense flat round size="sm" icon="delete" color="negative" @click="remove(p.row)"/></q-td></template>
      </q-table>
    </q-card>
    <q-dialog v-model="dialog"><q-card style="width:480px;max-width:94vw"><q-form @submit="save">
      <q-card-section class="row items-center q-py-sm"><div class="text-subtitle1 text-weight-bold">{{form.id?'Editar promoción':'Nueva promoción'}}</div><q-space/><q-btn flat round dense icon="close" v-close-popup/></q-card-section><q-separator/>
      <q-card-section class="row q-col-gutter-sm">
        <q-input v-model="form.nombre" v-uppercase dense outlined label="Nombre (interno) *" hint="Ej: SORTEO NAVIDAD 2026" class="col-12"/>
        <q-input v-model="form.titulo" v-uppercase dense outlined label="Título del cupón *" hint="Ej: SORTEO SECADORA DE ROPA" class="col-12"/>
        <q-input v-model="form.subtitulo" v-uppercase dense outlined label="Subtítulo" hint="Ej: BODEGA BAJO CERO · SORTEO EL 24 DE DICIEMBRE" class="col-12"/>
        <q-input v-model.number="form.monto" dense outlined type="number" min="0.01" step="0.01" label="Compra mínima (Bs) *" class="col-6"/>
        <q-input v-model.number="form.valor_ficha" dense outlined type="number" min="0.01" step="0.01" label="Valor por ficha (Bs)" hint="Ej: 30 → compra de 90 gana 3 fichas · vacío = 1 ficha" class="col-6"/>
        <q-input v-model.number="form.impresiones" dense outlined type="number" min="1" max="5" step="1" label="Impresiones por ficha *" hint="Ej: 2 → una para el cliente y otra para la tienda" class="col-6"/>
        <div class="col-6 flex items-center"><q-toggle v-model="form.estado" color="positive" :label="form.estado?'Activa':'Inactiva'"/></div>
      </q-card-section>
      <q-card-actions align="right"><q-btn flat no-caps label="Cancelar" v-close-popup/><q-btn unelevated no-caps color="primary" icon="save" label="Guardar" type="submit" :loading="saving"/></q-card-actions>
    </q-form></q-card></q-dialog>
  </q-page>
</template>
<script setup>
import { computed, getCurrentInstance, reactive, ref } from 'vue'
import { actualizarPromociones } from '../../addons/promociones'
const {proxy}=getCurrentInstance(),rows=ref([]),search=ref(''),loading=ref(false),dialog=ref(false),saving=ref(false),can=p=>proxy.$store.hasPermission(p)
const empty=()=>({id:null,nombre:'',titulo:'',subtitulo:'',monto:null,valor_ficha:null,impresiones:1,estado:true}),form=reactive(empty())
const columns=[{name:'nombre',label:'Nombre',field:'nombre',align:'left'},{name:'titulo',label:'Cupón',field:'titulo',align:'left'},{name:'monto',label:'Compra mínima',field:'monto',align:'right'},{name:'valor_ficha',label:'Fichas',field:'valor_ficha',align:'left'},{name:'impresiones',label:'Impresiones',field:'impresiones',align:'center'},{name:'estado',label:'Activa',field:'estado',align:'center'},{name:'actions',label:'',align:'right'}]
const filtered=computed(()=>{const q=search.value?.toLowerCase().trim();return q?rows.value.filter(i=>[i.nombre,i.titulo,i.subtitulo].some(v=>String(v||'').toLowerCase().includes(q))):rows.value})
const error=(e,msg)=>proxy.$alert.error(Object.values(e.response?.data?.errors||{})[0]?.[0]||e.response?.data?.message||msg)
// Tras cada cambio se refresca la copia del dispositivo que usa la caja.
function load(){loading.value=true;proxy.$axios.get('/promociones').then(r=>rows.value=r.data).catch(e=>error(e,'No se pudieron cargar las promociones')).finally(()=>loading.value=false);actualizarPromociones(proxy.$axios)}
function openForm(row=null){Object.assign(form,empty(),row||{});dialog.value=true}
function save(){if(!String(form.nombre||'').trim()||!String(form.titulo||'').trim()||!(Number(form.monto)>0))return proxy.$alert.error('Nombre, título y monto son obligatorios');if(!(Number(form.impresiones)>=1&&Number(form.impresiones)<=5))return proxy.$alert.error('Las impresiones deben ser de 1 a 5');saving.value=true;const req=form.id?proxy.$axios.put(`/promociones/${form.id}`,form):proxy.$axios.post('/promociones',form);req.then(()=>{proxy.$alert.success('Promoción guardada');dialog.value=false;load()}).catch(e=>error(e,'No se pudo guardar')).finally(()=>saving.value=false)}
function toggle(row,estado){proxy.$axios.put(`/promociones/${row.id}`,{...row,estado}).then(()=>load()).catch(e=>error(e,'No se pudo cambiar el estado'))}
function remove(row){proxy.$alert.dialog(`¿Eliminar la promoción ${row.nombre}?`,'Ya no se imprimirán sus cupones.').onOk(()=>proxy.$axios.delete(`/promociones/${row.id}`).then(()=>{proxy.$alert.success('Promoción eliminada');load()}).catch(e=>error(e,'No se pudo eliminar')))}
load()
</script>
