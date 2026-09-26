<template>
  <q-page class="q-pa-sm">
    <div class="row items-center q-mb-sm"><div><div class="text-subtitle1 text-weight-bold">Clientes</div><div class="text-caption text-grey-7">Padrón de compradores para la facturación · se completa solo al facturar</div></div><q-space/><q-btn v-if="can('Crear Clientes')" dense unelevated color="primary" icon="person_add" label="Nuevo cliente" no-caps @click="openForm()"/></div>
    <q-card flat bordered>
      <q-card-section class="q-pa-sm"><q-input v-model="search" dense outlined clearable debounce="300" placeholder="Buscar nombre, documento o correo" @update:model-value="() => load(true)"><template #prepend><q-icon name="search"/></template></q-input></q-card-section>
      <q-table flat dense :rows="rows" :columns="columns" row-key="id" :loading="loading" v-model:pagination="pagination" :rows-per-page-options="[20,50,100]" @request="onRequest">
        <template #body-cell-documento="p"><q-td :props="p"><q-badge outline color="primary" :label="p.row.tipo_documento" class="q-mr-xs"/>{{p.row.numero_documento}}{{p.row.complemento?'-'+p.row.complemento:''}}</q-td></template>
        <template #body-cell-contacto="p"><q-td :props="p"><div>{{p.row.email||'—'}}</div><div class="text-caption">{{p.row.telefono||''}} {{p.row.direccion||''}}</div></q-td></template>
        <template #body-cell-actions="p"><q-td :props="p"><q-btn v-if="can('Editar Clientes')" dense flat round size="sm" icon="edit" color="primary" @click="openForm(p.row)"/><q-btn v-if="can('Eliminar Clientes')" dense flat round size="sm" icon="delete" color="negative" @click="remove(p.row)"/></q-td></template>
      </q-table>
    </q-card>
    <q-dialog v-model="dialog"><q-card style="width:480px;max-width:94vw"><q-form @submit="save">
      <q-card-section class="row items-center q-py-sm"><div class="text-subtitle1 text-weight-bold">{{form.id?'Editar cliente':'Nuevo cliente'}}</div><q-space/><q-btn flat round dense icon="close" v-close-popup/></q-card-section><q-separator/>
      <q-card-section class="row q-col-gutter-sm">
        <q-select v-model="form.tipo_documento" :options="['CI','NIT','CEX','PAS','OD']" dense outlined label="Tipo" class="col-3"/>
        <q-input v-model="form.numero_documento" dense outlined label="Número de documento *" class="col" @blur="checkNit"/>
        <q-input v-if="form.tipo_documento==='CI'" v-model="form.complemento" v-uppercase dense outlined label="Compl." maxlength="5" class="col-2"/>
        <div v-if="nitValido!==null" class="col-12 text-caption" :class="nitValido?'text-positive':'text-negative'"><q-icon :name="nitValido?'verified':'error'"/> {{nitValido?'NIT activo en el padrón de Impuestos':'El NIT no existe en el padrón de Impuestos'}}</div>
        <q-input v-model="form.nombre" v-uppercase dense outlined label="Nombre / razón social *" class="col-12"/>
        <q-input v-model="form.email" dense outlined type="email" label="Correo" class="col-12 col-sm-7"/>
        <q-input v-model="form.telefono" dense outlined label="Teléfono" class="col-12 col-sm-5"/>
        <q-input v-model="form.direccion" v-uppercase dense outlined label="Dirección" class="col-12"/>
      </q-card-section>
      <q-card-actions align="right"><q-btn flat no-caps label="Cancelar" v-close-popup/><q-btn unelevated no-caps color="primary" icon="save" label="Guardar" type="submit" :loading="saving"/></q-card-actions>
    </q-form></q-card></q-dialog>
  </q-page>
</template>
<script setup>
import { getCurrentInstance, reactive, ref } from 'vue'
const {proxy}=getCurrentInstance(),rows=ref([]),search=ref(''),loading=ref(false),dialog=ref(false),saving=ref(false),nitValido=ref(null),can=p=>proxy.$store.hasPermission(p)
const empty=()=>({id:null,tipo_documento:'CI',numero_documento:'',complemento:'',nombre:'',email:'',telefono:'',direccion:''}),form=reactive(empty())
const pagination=ref({page:1,rowsPerPage:20,rowsNumber:0})
const columns=[{name:'nombre',label:'Nombre / razón social',field:'nombre',align:'left'},{name:'documento',label:'Documento',field:'numero_documento',align:'left'},{name:'contacto',label:'Contacto',field:'email',align:'left'},{name:'ventas_count',label:'Compras',field:'ventas_count',align:'center'},{name:'actions',label:'',align:'right'}]
function load(resetPage=false){onRequest({pagination:resetPage?{...pagination.value,page:1}:pagination.value})}
function onRequest({pagination:p}){loading.value=true;proxy.$axios.get('/clientes',{params:{q:search.value,page:p.page,per_page:p.rowsPerPage}}).then(r=>{rows.value=r.data.data;pagination.value={...p,rowsNumber:r.data.total}}).catch(e=>proxy.$alert.error(e.response?.data?.message||'No se pudieron cargar los clientes')).finally(()=>loading.value=false)}
function openForm(row=null){Object.assign(form,empty(),row||{});nitValido.value=null;dialog.value=true}
function checkNit(){nitValido.value=null;if(form.tipo_documento!=='NIT'||!String(form.numero_documento||'').trim())return;proxy.$axios.get('/clientes-verificar-nit',{params:{nit:form.numero_documento}}).then(r=>nitValido.value=r.data.valido).catch(()=>{})}
function save(){if(!String(form.numero_documento||'').trim()||!String(form.nombre||'').trim())return proxy.$alert.error('Documento y nombre son obligatorios');saving.value=true;const req=form.id?proxy.$axios.put(`/clientes/${form.id}`,form):proxy.$axios.post('/clientes',form);req.then(()=>{proxy.$alert.success('Cliente guardado');dialog.value=false;load()}).catch(e=>proxy.$alert.error(Object.values(e.response?.data?.errors||{})[0]?.[0]||e.response?.data?.message||'No se pudo guardar')).finally(()=>saving.value=false)}
function remove(row){proxy.$alert.dialog(`¿Eliminar a ${row.nombre}?`,'Sus facturas anteriores no cambian.').onOk(()=>proxy.$axios.delete(`/clientes/${row.id}`).then(()=>{proxy.$alert.success('Cliente eliminado');load()}).catch(e=>proxy.$alert.error(e.response?.data?.message||'No se pudo eliminar')))}
load()
</script>
