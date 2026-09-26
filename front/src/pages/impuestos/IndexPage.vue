<template>
  <q-page class="q-pa-sm">
    <div class="row items-center q-mb-sm"><div><div class="text-subtitle1 text-weight-bold">Impuestos</div><div class="text-caption text-grey-7">Facturación {{estado.modalidad?.toLowerCase()||'computarizada'}} en línea · SIAT</div></div><q-space/>
      <q-badge :color="estado.ambiente==='PRODUCCIÓN'?'positive':'orange'" :label="estado.ambiente||'—'" class="q-mr-xs"/><q-badge v-if="estado.habilitado===false" color="negative" label="SIAT DESACTIVADO"/>
    </div>
    <q-tabs v-model="tab" dense no-caps align="left" active-color="primary" indicator-color="primary" class="text-grey-8 q-mb-sm">
      <q-tab name="estado" icon="verified_user" label="Credenciales"/>
      <q-tab name="eventos" icon="cloud_sync" label="Eventos significativos"><q-badge v-if="estado.pendientes_evento" color="orange" floating :label="estado.pendientes_evento"/></q-tab>
      <q-tab name="catalogos" icon="category" label="Catálogos"/>
    </q-tabs>

    <q-tab-panels v-model="tab" animated keep-alive class="bg-transparent">
      <q-tab-panel name="estado" class="q-pa-none">
        <div class="row q-col-gutter-sm">
          <div class="col-12 col-md-4"><q-card flat bordered class="full-height"><q-card-section class="q-pa-sm">
            <div class="text-weight-bold q-mb-xs"><q-icon name="store" color="primary"/> Emisor</div>
            <div class="kv"><span>NIT</span><b>{{estado.nit||'—'}}</b></div><div class="kv"><span>Código de sistema</span><b>{{estado.codigo_sistema||'—'}}</b></div>
            <div class="kv"><span>Sucursal / punto de venta</span><b>{{estado.sucursal}} / {{estado.punto_venta}}</b></div><div class="kv"><span>Municipio</span><b>{{estado.municipio}}</b></div>
            <q-separator class="q-my-sm"/>
            <div class="row items-center"><q-icon :name="comunicacion?.online?'wifi':'wifi_off'" :color="comunicacion===null?'grey':comunicacion.online?'positive':'negative'" size="20px" class="q-mr-xs"/><span class="text-caption">{{comunicacion===null?'Sin probar':comunicacion.mensaje}}</span><q-space/><q-btn dense flat no-caps color="primary" icon="network_check" label="Probar" :loading="probando" @click="probar"/></div>
          </q-card-section></q-card></div>
          <div class="col-12 col-md-4"><q-card flat bordered class="full-height"><q-card-section class="q-pa-sm">
            <div class="row items-center q-mb-xs"><div class="text-weight-bold"><q-icon name="key" color="primary"/> CUIS</div><q-space/><q-btn dense unelevated no-caps size="sm" color="primary" icon="add" label="Generar CUIS" :loading="busy==='cuis'" @click="generar('cuis')"/></div>
            <template v-if="estado.cuis"><div class="text-h6 text-weight-bold">{{estado.cuis.codigo}}</div><div class="text-caption">Vigente hasta {{fecha(estado.cuis.vence_en)}}</div></template>
            <div v-else class="text-negative text-caption">Sin CUIS vigente: genérelo antes de facturar.</div>
            <div class="text-caption text-grey-7 q-mt-xs">Código de inicio de sistema, dura un año por punto de venta.</div>
          </q-card-section></q-card></div>
          <div class="col-12 col-md-4"><q-card flat bordered class="full-height"><q-card-section class="q-pa-sm">
            <div class="row items-center q-mb-xs"><div class="text-weight-bold"><q-icon name="today" color="primary"/> CUFD</div><q-space/><q-btn dense unelevated no-caps size="sm" color="primary" :icon="estado.cufd?'autorenew':'add'" :label="estado.cufd?'Renovar':'Generar CUFD'" :loading="busy==='cufds'" @click="generar('cufds')"/></div>
            <template v-if="estado.cufd"><div class="text-caption ellipsis"><b>{{estado.cufd.codigo}}</b></div><div class="text-caption">Control {{estado.cufd.codigo_control}} · vigente hasta <b>{{fecha(estado.cufd.vence_en)}}</b></div><div class="text-caption text-grey-7">{{estado.cufd.direccion}}</div></template>
            <div v-else class="text-negative text-caption">Sin CUFD vigente: se pide solo al facturar, o genérelo aquí.</div>
            <div class="text-caption text-grey-7 q-mt-xs">Código diario: vence a las 24 h y se renueva solo cada madrugada.</div>
          </q-card-section></q-card></div>

          <div class="col-12 col-md-6"><q-card flat bordered><q-card-section class="row items-center q-pa-sm"><div class="text-weight-bold"><q-icon name="token" color="primary"/> Token del SIAT</div><q-space/><q-btn dense flat no-caps color="primary" icon="add" label="Registrar token" @click="tokenDialog=true"/></q-card-section>
            <q-list dense separator><q-item v-for="t in tokens" :key="t.id"><q-item-section><q-item-label>Token #{{t.id}}</q-item-label><q-item-label caption>Vence {{fecha(t.vence_en)}}</q-item-label></q-item-section><q-item-section side><q-btn dense flat round size="sm" icon="delete" color="negative" @click="deleteToken(t)"/></q-item-section></q-item>
              <q-item v-if="!tokens.length"><q-item-section class="text-caption text-grey-7">{{estado.token_env?'Usando el token de SIAT_TOKEN (archivo .env).':'No hay token: regístrelo para poder facturar.'}}</q-item-section></q-item></q-list>
          </q-card></div>
          <div class="col-12 col-md-6"><q-card flat bordered><q-card-section class="q-pa-sm text-weight-bold"><q-icon name="history" color="primary"/> Historial de CUFD</q-card-section>
            <q-table flat dense :rows="cufds" :columns="cufdColumns" row-key="id" hide-pagination :rows-per-page-options="[0]"/>
          </q-card></div>
        </div>
      </q-tab-panel>

      <q-tab-panel name="eventos" class="q-pa-none">
        <q-card flat bordered class="q-mb-sm"><q-card-section class="q-pa-sm">
          <div class="text-weight-bold">Facturas emitidas fuera de línea: {{pendientes.length}}</div>
          <div class="text-caption text-grey-7 q-mb-sm">Cuando Impuestos no responde, la factura se emite igual (tipo de emisión fuera de línea) y queda aquí. Al volver la conexión se registra un evento significativo por cada CUFD y se envían en paquetes.</div>
          <div class="row q-col-gutter-sm items-center">
            <q-select v-model="motivo" :options="motivos" option-label="descripcion" option-value="codigo" emit-value map-options dense outlined label="Motivo del evento" class="col-12 col-md-5"/>
            <q-input v-model="descripcion" v-uppercase dense outlined label="Descripción (opcional)" class="col-12 col-md-4"/>
            <div class="col-12 col-md-3"><q-btn class="full-width" unelevated no-caps color="primary" icon="cloud_upload" label="Enviar a Impuestos" :disable="!pendientes.length||!motivo" :loading="enviando" @click="enviar"/></div>
          </div>
        </q-card-section>
          <q-table v-if="pendientes.length" flat dense :rows="pendientes" :columns="pendingColumns" row-key="id" :pagination="{rowsPerPage:10}"/>
        </q-card>
        <q-card flat bordered><q-card-section class="q-pa-sm text-weight-bold"><q-icon name="history" color="primary"/> Eventos registrados</q-card-section>
          <q-table flat dense :rows="eventos" :columns="eventColumns" row-key="id" :pagination="{rowsPerPage:10}">
            <template #body-cell-estado="p"><q-td :props="p"><q-badge :color="{VALIDADO:'positive',OBSERVADO:'negative',ERROR:'negative',EN_VALIDACION:'orange'}[p.value]||'grey'" :label="p.value"/><q-tooltip v-if="p.row.mensaje" max-width="320px">{{p.row.mensaje}}</q-tooltip></q-td></template>
            <template #body-cell-actions="p"><q-td :props="p"><q-btn v-if="p.row.estado==='EN_VALIDACION'" dense flat no-caps size="sm" color="primary" icon="refresh" label="Revalidar" @click="revalidar(p.row)"/></q-td></template>
          </q-table>
        </q-card>
      </q-tab-panel>

      <q-tab-panel name="catalogos" class="q-pa-none">
        <q-card flat bordered class="q-mb-sm"><q-card-section class="row items-center q-pa-sm">
          <div><div class="text-weight-bold">Catálogos del SIN</div><div class="text-caption text-grey-7"><span v-for="(n,t) in estado.catalogos" :key="t" class="q-mr-sm">{{t}}: {{n}}</span><span v-if="!Object.keys(estado.catalogos||{}).length">Sin sincronizar</span></div></div><q-space/>
          <q-btn unelevated no-caps color="primary" icon="sync" label="Sincronizar" :loading="busy==='sync'" @click="sincronizar"/>
        </q-card-section></q-card>
        <q-card flat bordered><q-card-section class="q-pa-sm"><div class="text-weight-bold">Producto SIN por categoría</div><div class="text-caption text-grey-7">Cada línea de la factura se declara con la actividad y el producto SIN de la categoría del producto. Sin asignar se usa «productos de abarrotes».</div></q-card-section>
          <q-table flat dense :rows="categorias" :columns="categoryColumns" row-key="id" :pagination="{rowsPerPage:0}" hide-pagination>
            <template #body-cell-actividad_economica="p"><q-td :props="p"><q-select v-model="p.row.actividad_economica" :options="actividades" option-label="label" option-value="codigo" emit-value map-options dense borderless options-dense style="min-width:200px" @update:model-value="v=>{p.row.codigo_producto_sin=null}"/></q-td></template>
            <template #body-cell-codigo_producto_sin="p"><q-td :props="p"><q-select v-model="p.row.codigo_producto_sin" :options="productosDe(p.row.actividad_economica)" option-label="label" option-value="codigo" emit-value map-options dense borderless options-dense clearable use-input input-debounce="0" style="min-width:320px" @filter="filtrarProductos" @update:model-value="guardarCategoria(p.row)"/></q-td></template>
          </q-table>
        </q-card>
      </q-tab-panel>
    </q-tab-panels>

    <q-dialog v-model="tokenDialog"><q-card style="width:520px;max-width:96vw"><q-card-section class="text-subtitle1 text-weight-bold">Registrar token delegado del SIAT</q-card-section><q-card-section class="q-pt-none"><q-input v-model="nuevoToken" type="textarea" outlined autogrow label="Token (JWT)"/></q-card-section><q-card-actions align="right"><q-btn flat no-caps label="Cancelar" v-close-popup/><q-btn unelevated no-caps color="primary" label="Guardar" :loading="busy==='token'" @click="saveToken"/></q-card-actions></q-card></q-dialog>
  </q-page>
</template>

<script setup>
import { computed, getCurrentInstance, onMounted, ref } from 'vue'
const {proxy}=getCurrentInstance()
const tab=ref('estado'),estado=ref({}),comunicacion=ref(null),probando=ref(false),busy=ref(null),tokens=ref([]),cufds=ref([]),tokenDialog=ref(false),nuevoToken=ref('')
const motivos=ref([]),motivo=ref(2),descripcion=ref(''),pendientes=ref([]),eventos=ref([]),enviando=ref(false)
const categorias=ref([]),catalogoActividades=ref([]),catalogoProductos=ref([]),filtroProducto=ref('')
const fecha=v=>v?new Date(v).toLocaleString('es-BO'):'—',money=v=>Number(v||0).toFixed(2)
const err=(e,m)=>proxy.$alert.error(Object.values(e.response?.data?.errors||{})[0]?.[0]||e.response?.data?.message||m)
const cufdColumns=[{name:'codigo_control',label:'Control',field:'codigo_control',align:'left'},{name:'created_at',label:'Obtenido',field:r=>fecha(r.created_at),align:'left'},{name:'vence_en',label:'Vence',field:r=>fecha(r.vence_en),align:'left'}]
const pendingColumns=[{name:'numero_factura',label:'Factura',field:'numero_factura',align:'left'},{name:'numero',label:'Venta',field:'numero',align:'left'},{name:'fecha',label:'Emitida',field:r=>fecha(r.fecha_emision_siat),align:'left'},{name:'cliente',label:'Cliente',field:'cliente_nombre',align:'left'},{name:'total',label:'Total',field:'total',format:v=>`Bs ${money(v)}`,align:'right'}]
const eventColumns=[{name:'id',label:'#',field:'id',align:'left'},{name:'motivo',label:'Motivo',field:r=>motivos.value.find(m=>m.codigo===r.codigo_motivo)?.descripcion||r.codigo_motivo,align:'left'},{name:'rango',label:'Periodo',field:r=>`${fecha(r.inicio)} – ${fecha(r.fin)}`,align:'left'},{name:'cantidad_facturas',label:'Facturas',field:'cantidad_facturas',align:'center'},{name:'codigo_evento',label:'Cód. evento',field:'codigo_evento',align:'left'},{name:'estado',label:'Estado',field:'estado',align:'center'},{name:'actions',label:'',align:'right'}]
const categoryColumns=[{name:'nombre',label:'Categoría',field:'nombre',align:'left'},{name:'productos_count',label:'Productos',field:'productos_count',align:'center'},{name:'actividad_economica',label:'Actividad económica',field:'actividad_economica',align:'left'},{name:'codigo_producto_sin',label:'Producto SIN',field:'codigo_producto_sin',align:'left'}]
const actividades=computed(()=>catalogoActividades.value.map(a=>({codigo:a.codigo,label:`${a.codigo} · ${a.descripcion}`})))
function productosDe(actividad){const q=filtroProducto.value.toLowerCase();return catalogoProductos.value.filter(p=>p.codigo_actividad===(actividad||'4711100')&&(!q||`${p.codigo} ${p.descripcion}`.toLowerCase().includes(q))).map(p=>({codigo:Number(p.codigo),label:`${p.codigo} · ${p.descripcion}`}))}
function filtrarProductos(val,update){update(()=>{filtroProducto.value=val||''})}

function loadEstado(){return proxy.$axios.get('/siat/estado').then(r=>estado.value=r.data).catch(e=>err(e,'No se pudo leer el estado de Impuestos'))}
function loadCredenciales(){proxy.$axios.get('/siat/tokens').then(r=>tokens.value=r.data);proxy.$axios.get('/siat/cufds').then(r=>cufds.value=r.data.data)}
function loadEventos(){return proxy.$axios.get('/siat/eventos').then(r=>{motivos.value=r.data.motivos;pendientes.value=r.data.pendientes;eventos.value=r.data.eventos})}
function loadCatalogos(){proxy.$axios.get('/siat/categorias').then(r=>categorias.value=r.data);proxy.$axios.get('/siat/catalogos/actividad').then(r=>catalogoActividades.value=r.data);proxy.$axios.get('/siat/catalogos/producto').then(r=>catalogoProductos.value=r.data)}
function probar(){probando.value=true;proxy.$axios.get('/siat/estado',{params:{comunicacion:1}}).then(r=>{estado.value=r.data;comunicacion.value=r.data.comunicacion}).catch(e=>err(e,'No se pudo probar la conexión')).finally(()=>probando.value=false)}
function generar(tipo){const run=forzar=>{busy.value=tipo;proxy.$axios.post(`/siat/${tipo}`,{forzar}).then(()=>{proxy.$alert.success(tipo==='cuis'?'CUIS listo':'CUFD listo');loadEstado();loadCredenciales()}).catch(e=>err(e,'Impuestos no respondió')).finally(()=>busy.value=null)}
  if(tipo==='cufds'&&estado.value.cufd)return proxy.$alert.dialog('¿Pedir un CUFD nuevo?','El actual sigue vigente; las facturas nuevas usarán el nuevo.').onOk(()=>run(true));run(false)}
function saveToken(){busy.value='token';proxy.$axios.post('/siat/tokens',{token:nuevoToken.value}).then(()=>{proxy.$alert.success('Token registrado');tokenDialog.value=false;nuevoToken.value='';loadCredenciales()}).catch(e=>err(e,'Token inválido')).finally(()=>busy.value=null)}
function deleteToken(t){proxy.$alert.dialog(`¿Eliminar el token #${t.id}?`).onOk(()=>proxy.$axios.delete(`/siat/tokens/${t.id}`).then(loadCredenciales))}
function enviar(){proxy.$alert.dialog(`¿Enviar ${pendientes.value.length} factura(s) a Impuestos?`,'Se registrará un evento significativo por cada CUFD y se validarán los paquetes. Puede tardar.').onOk(()=>{enviando.value=true;proxy.$axios.post('/siat/eventos',{codigo_motivo:motivo.value,descripcion:descripcion.value},{timeout:600000}).then(r=>{const ev=r.data.eventos||[],ok=ev.filter(e=>e.estado==='VALIDADO').length;if(ok===ev.length)proxy.$alert.success('Facturas enviadas y validadas',`${ev.length} evento(s) validado(s)`);else proxy.$alert.warning('Revise el resultado de los eventos',ev.map(e=>`#${e.id} ${e.estado}: ${e.mensaje||''}`).join(' · '))}).catch(e=>err(e,'No se pudo enviar')).finally(()=>{enviando.value=false;loadEventos();loadEstado()})})}
function revalidar(ev){proxy.$axios.post(`/siat/eventos/${ev.id}/revalidar`).then(r=>{proxy.$alert.info(`Evento #${ev.id}: ${r.data.estado}`);loadEventos();loadEstado()}).catch(e=>err(e,'No se pudo revalidar'))}
function guardarCategoria(row){proxy.$axios.put(`/siat/categorias/${row.id}`,{actividad_economica:row.actividad_economica||'4711100',codigo_producto_sin:row.codigo_producto_sin}).then(()=>proxy.$alert.success(`${row.nombre} actualizada`)).catch(e=>err(e,'No se pudo guardar'))}
function sincronizar(){busy.value='sync';proxy.$axios.post('/siat/sincronizar',{},{timeout:120000}).then(()=>{proxy.$alert.success('Catálogos sincronizados');loadEstado();loadCatalogos()}).catch(e=>err(e,'No se pudo sincronizar')).finally(()=>busy.value=null)}
onMounted(()=>{loadEstado();loadCredenciales();loadEventos();loadCatalogos()})
</script>

<style scoped>.kv{display:flex;justify-content:space-between;font-size:12px;line-height:20px}.kv span{color:#78909c}</style>
