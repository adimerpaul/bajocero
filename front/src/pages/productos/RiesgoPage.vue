<template>
  <q-page class="q-pa-sm">
    <div class="row items-center q-mb-sm q-col-gutter-sm">
      <div class="col-12 col-md"><div class="text-subtitle1 text-weight-bold">Productos en riesgo</div><div class="text-caption text-grey-7">Se llena solo al aplicar un inventario (lo contado con vencimiento) · ordenado por vencimiento más cercano · no mueve stock ni inventario</div></div>
      <div class="col-12 col-md-auto row q-gutter-xs items-center">
        <q-input v-model="search" dense outlined clearable debounce="300" placeholder="Buscar producto, lote, usuario" style="width:240px" @update:model-value="()=>load()"><template #prepend><q-icon name="search"/></template></q-input>
        <q-btn v-if="canManage" dense unelevated no-caps color="orange-9" icon="add" label="Agregar" @click="openAdd"/>
        <q-btn dense flat no-caps icon="schedule" label="Por vencer" to="/productos/por-vencer"/>
        <q-btn dense flat round icon="refresh" :loading="loading" @click="load"/>
      </div>
    </div>
    <q-card flat bordered>
      <q-tabs v-model="tab" dense no-caps align="left" active-color="primary" indicator-color="primary" class="text-grey-8" @update:model-value="()=>load()">
        <q-tab name="ACTIVO" icon="warning" :label="`Activos (${totals.ACTIVO})`"/>
        <q-tab name="QUITADO" icon="history" :label="`Quitados (${totals.QUITADO})`"/>
        <q-tab name="TODOS" icon="list" :label="`Todos (${totals.ACTIVO+totals.QUITADO})`"/>
      </q-tabs>
      <q-separator/>
      <q-table flat dense :rows="rows" :columns="visibleColumns" row-key="id" :loading="loading" v-model:pagination="pagination" :rows-per-page-options="[25,50,100,200]" no-data-label="Sin productos" @request="onRequest">
        <template #body="p"><q-tr :props="p" :class="p.row.estado==='QUITADO'?'row-removed':''">
          <q-td key="foto" :props="p"><div class="thumb"><img v-if="p.row.foto" :src="photoUrl(p.row.foto)" loading="lazy"/><q-icon v-else name="inventory_2" size="22px" color="grey-4"/></div><q-tooltip v-if="p.row.foto" anchor="center right" self="center left" class="bg-white shadow-4 q-pa-xs"><img :src="photoUrl(p.row.foto)" style="width:180px;height:180px;object-fit:contain"/></q-tooltip></q-td>
          <q-td key="producto" :props="p"><b>{{p.row.nombre}}</b><div class="text-caption text-grey-7">{{p.row.codigo}}<span v-if="p.row.lote"> · Lote {{p.row.lote}}</span><span v-if="p.row.cantidad!==null"> · {{qty(p.row.cantidad,p.row.unidad)}} {{p.row.unidad}}</span></div><div v-if="p.row.observacion" class="text-caption text-grey-8"><q-icon name="notes" size="13px"/> {{p.row.observacion}}</div></q-td>
          <q-td key="vence" :props="p"><template v-if="p.row.fecha_vencimiento">{{date(p.row.fecha_vencimiento)}}<div><q-badge :color="daysLeft(p.row)<0?'negative':daysLeft(p.row)<=7?'orange':'warning'" :label="daysLeft(p.row)<0?`${Math.abs(daysLeft(p.row))} días vencido`:daysLeft(p.row)===0?'Vence hoy':`${daysLeft(p.row)} días`"/></div></template><span v-else class="text-grey-6">—</span></q-td>
          <q-td key="origen" :props="p"><q-badge outline :color="p.row.origen==='MANUAL'?'grey-7':p.row.origen==='INVENTARIO'?'teal':'primary'" :label="originLabel[p.row.origen]||p.row.origen"/><div v-if="p.row.documento" class="text-caption text-grey-7">{{p.row.documento}}</div></q-td>
          <q-td key="agregado" :props="p"><div class="text-weight-medium"><q-icon name="add_circle" color="orange-9" size="14px"/> {{p.row.agregado_por_nombre}}</div><div class="text-caption text-grey-7">{{dateTime(p.row.agregado_at)}}</div></q-td>
          <q-td key="quitado" :props="p"><template v-if="p.row.estado==='QUITADO'"><div class="text-weight-medium"><q-icon name="remove_circle" color="grey-7" size="14px"/> {{p.row.quitado_por_nombre}}</div><div class="text-caption text-grey-7">{{dateTime(p.row.quitado_at)}}</div><div v-if="p.row.motivo_quitado" class="text-caption text-grey-8">{{p.row.motivo_quitado}}</div></template><q-badge v-else color="orange-9" label="ACTIVO"/></q-td>
          <q-td key="acciones" :props="p">
            <q-btn v-if="canManage&&p.row.estado==='ACTIVO'" dense flat no-caps size="sm" color="negative" icon="remove_circle_outline" label="Quitar" :loading="p.row._saving" @click="remove(p.row)"/>
            <q-btn v-else-if="canManage&&p.row.producto_id&&!p.row.ya_activo" dense flat no-caps size="sm" color="orange-9" icon="replay" label="Volver a agregar" :loading="p.row._saving" @click="readd(p.row)"><q-tooltip>Crea un registro nuevo; este queda en el historial</q-tooltip></q-btn>
          </q-td>
        </q-tr></template>
      </q-table>
    </q-card>

    <q-dialog v-model="addDialog"><q-card style="width:460px;max-width:94vw"><q-form @submit="save">
      <q-card-section class="row items-center q-py-sm"><q-icon name="warning" color="orange-9" size="22px" class="q-mr-xs"/><b>Agregar producto en riesgo</b><q-space/><q-btn flat round dense icon="close" v-close-popup/></q-card-section><q-separator/>
      <q-card-section class="q-gutter-sm">
        <q-card v-if="form.producto" flat bordered class="row items-center no-wrap q-pa-xs bg-orange-1">
          <div class="thumb big"><img v-if="form.producto.foto" :src="photoUrl(form.producto.foto)"/><q-icon v-else name="inventory_2" size="30px" color="grey-4"/></div>
          <div class="col q-ml-sm" style="min-width:0"><div class="text-weight-bold ellipsis">{{form.producto.nombre}}</div><div class="text-caption text-grey-7">{{form.producto.codigo}} · Stock {{qty(form.producto.stock_inicial,form.producto.unidad)}} {{form.producto.unidad}}</div></div>
          <q-btn dense flat no-caps size="sm" color="primary" icon="swap_horiz" label="Cambiar" @click="form.producto=null;$nextTick(()=>productInput?.focus())"/>
        </q-card>
        <template v-else>
          <q-input ref="productInput" v-model="productSearch" dense outlined autofocus clearable debounce="300" label="Buscar producto *" placeholder="Nombre, código o código de barras" @update:model-value="()=>loadProductList()"><template #prepend><q-icon name="search"/></template><template #append><q-spinner v-if="loadingList" size="16px" color="primary"/></template></q-input>
          <q-list bordered separator dense class="product-list">
            <q-item v-for="prod in productList" :key="prod.id" clickable @click="form.producto=prod">
              <q-item-section avatar><div class="thumb"><img v-if="prod.foto" :src="photoUrl(prod.foto)" loading="lazy"/><q-icon v-else name="inventory_2" size="22px" color="grey-4"/></div></q-item-section>
              <q-item-section><q-item-label class="text-weight-medium">{{prod.nombre}}</q-item-label><q-item-label caption>{{prod.codigo}}<span v-if="prod.categoria"> · {{prod.categoria}}</span> · Stock {{qty(prod.stock_inicial,prod.unidad)}} {{prod.unidad}}</q-item-label></q-item-section>
            </q-item>
            <q-item v-if="!productList.length&&!loadingList"><q-item-section class="text-grey-6 text-center">Sin productos</q-item-section></q-item>
          </q-list>
          <div v-if="productLastPage>1" class="row justify-center"><q-pagination v-model="productPage" :max="productLastPage" :max-pages="5" boundary-numbers dense size="sm" color="primary" @update:model-value="loadProductList(false)"/></div>
        </template>
        <div class="row q-col-gutter-sm"><q-input v-model="form.lote" v-uppercase dense outlined label="Lote" class="col-6"/><q-input v-model="form.fecha_vencimiento" dense outlined type="date" label="Vencimiento" stack-label class="col-6"/></div>
        <q-input v-model.number="form.cantidad" dense outlined type="number" min="0" step="0.001" label="Cantidad" :suffix="form.producto?.unidad"/>
        <q-input v-model="form.observacion" v-uppercase dense outlined autogrow label="Observación"/>
      </q-card-section>
      <q-card-actions align="right"><q-btn flat label="Cancelar" no-caps v-close-popup/><q-btn color="orange-9" unelevated icon="add" label="Agregar" no-caps type="submit" :loading="saving"/></q-card-actions>
    </q-form></q-card></q-dialog>
  </q-page>
</template>
<script setup>
import { computed, getCurrentInstance, onMounted, reactive, ref } from 'vue'
const {proxy}=getCurrentInstance(),rows=ref([]),totals=ref({ACTIVO:0,QUITADO:0}),loading=ref(false),saving=ref(false),search=ref(''),tab=ref('ACTIVO'),addDialog=ref(false),productList=ref([]),productSearch=ref(''),productPage=ref(1),productLastPage=ref(1),loadingList=ref(false),productInput=ref(null),pagination=ref({page:1,rowsPerPage:25,rowsNumber:0})
const emptyForm=()=>({producto:null,lote:'',fecha_vencimiento:'',cantidad:null,observacion:''}),form=reactive(emptyForm())
const canManage=computed(()=>proxy.$store.hasPermission('Gestionar Productos en Riesgo'))
const originLabel={MANUAL:'Manual',POR_VENCER:'Por vencer',VENCIDO:'Vencido',INVENTARIO:'Inventario'}
const columns=[{name:'foto',label:'',field:'foto',align:'center'},{name:'producto',label:'Producto',field:'nombre',align:'left'},{name:'vence',label:'Vencimiento',field:'fecha_vencimiento',align:'center'},{name:'origen',label:'Origen',field:'origen',align:'center'},{name:'agregado',label:'Agregado por',field:'agregado_at',align:'left'},{name:'quitado',label:'Quitado por',field:'quitado_at',align:'left'},{name:'acciones',label:'',field:'id',align:'center'}]
const visibleColumns=computed(()=>tab.value==='ACTIVO'?columns.filter(c=>c.name!=='quitado'):columns)
const photoUrl=path=>`${proxy.$imgBase}/images/${path}`,qty=(v,u)=>Number(v||0).toFixed(u==='KG'?3:0),date=v=>new Date(`${String(v).slice(0,10)}T12:00:00`).toLocaleDateString('es-BO'),dateTime=v=>v?new Date(v).toLocaleString('es-BO',{dateStyle:'short',timeStyle:'short'}):''
const daysLeft=r=>Math.round((new Date(`${String(r.fecha_vencimiento).slice(0,10)}T12:00:00`)-new Date(new Date().toDateString()+' 12:00:00'))/86400000)
// Paginado en el servidor; buscar o cambiar de pestaña vuelve a la página 1.
function load(resetPage=true){if(resetPage)pagination.value.page=1;loading.value=true;proxy.$axios.get('/productos-riesgo',{params:{estado:tab.value,q:search.value||'',page:pagination.value.page,per_page:pagination.value.rowsPerPage}}).then(r=>{rows.value=r.data.data;totals.value=r.data.totales;pagination.value.rowsNumber=r.data.total;if(pagination.value.page>(r.data.last_page||1)){pagination.value.page=r.data.last_page||1;load(false)}}).catch(e=>proxy.$alert.error(e.response?.data?.message||'No se pudo cargar')).finally(()=>loading.value=false)}
function onRequest({pagination:p}){pagination.value.page=p.page;pagination.value.rowsPerPage=p.rowsPerPage;load(false)}
// Buscador del diálogo: lista filtrada en el servidor, 8 por página, con foto.
let productRequest=0
function loadProductList(reset=true){if(reset)productPage.value=1;const request=++productRequest;loadingList.value=true;proxy.$axios.get('/productos',{params:{q:productSearch.value||'',per_page:8,page:productPage.value}}).then(r=>{if(request!==productRequest)return;productList.value=r.data.data;productLastPage.value=r.data.last_page||1}).catch(()=>{if(request===productRequest)productList.value=[]}).finally(()=>{if(request===productRequest)loadingList.value=false})}
function openAdd(){Object.assign(form,emptyForm());productSearch.value='';addDialog.value=true;loadProductList()}
function post(payload,done){return proxy.$axios.post('/productos-riesgo',payload).then(r=>{proxy.$alert.success(`${r.data.nombre} agregado a productos en riesgo`);done?.();load()}).catch(e=>proxy.$alert.error(Object.values(e.response?.data?.errors||{})[0]?.[0]||e.response?.data?.message||'No se pudo agregar'))}
function save(){if(!form.producto)return proxy.$alert.error('Seleccione un producto');saving.value=true;post({producto_id:form.producto.id,lote:form.lote||null,fecha_vencimiento:form.fecha_vencimiento||null,cantidad:form.cantidad===''?null:form.cantidad,origen:'MANUAL',observacion:form.observacion||null},()=>addDialog.value=false).finally(()=>saving.value=false)}
// Quitar nunca borra: la fila pasa a QUITADO con quién y cuándo.
function remove(row){proxy.$alert.dialogPrompt(`¿Quitar ${row.nombre}${row.lote?` (lote ${row.lote})`:''} de productos en riesgo? Motivo (opcional):`).onOk(reason=>{row._saving=true;proxy.$axios.put(`/productos-riesgo/${row.id}/quitar`,{motivo:reason||null}).then(()=>{proxy.$alert.success(`${row.nombre} quitado`);load(false)}).catch(e=>proxy.$alert.error(e.response?.data?.message||'No se pudo quitar')).finally(()=>row._saving=false)})}
function readd(row){proxy.$alert.confirm(`¿Volver a agregar ${row.nombre} a productos en riesgo?`).onOk(()=>{row._saving=true;post({producto_id:row.producto_id,lote:row.lote,fecha_vencimiento:row.fecha_vencimiento,cantidad:row.cantidad,origen:row.origen,observacion:row.observacion}).finally(()=>row._saving=false)})}
onMounted(load)
</script>
<style scoped>
.thumb{width:40px;height:40px;border-radius:4px;background:#fff7f6;display:flex;align-items:center;justify-content:center;overflow:hidden}.thumb.big{width:56px;height:56px;flex:0 0 56px;background:#fff}.product-list{max-height:330px;overflow:auto}.thumb img{width:100%;height:100%;object-fit:contain}
.row-removed{background:#fafafa;color:#757575}
</style>
