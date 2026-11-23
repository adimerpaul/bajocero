<template>
  <q-page class="q-pa-sm">
    <div class="row items-center q-mb-sm">
      <div>
        <div class="text-subtitle1 text-weight-bold">Revisión {{header.numero}}
          <q-badge :color="stateColor" :label="header.estado==='BORRADOR'?'EN REVISIÓN':header.estado" class="q-ml-xs"/>
        </div>
        <div class="text-caption text-grey-7">{{header.descripcion||'Cuenta el stock físico de la tienda'}} · {{itemsTotal}} productos revisados</div>
      </div>
      <q-space/>
      <q-btn dense flat icon="insights" label="Avance" no-caps class="q-mr-xs" :to="`/inventario/${id}/avance`"/>
      <q-btn dense flat icon="warehouse" label="Inventario" no-caps to="/inventario"/>
    </div>

    <q-banner v-if="!editable" dense class="bg-grey-3 text-grey-9 q-mb-sm rounded-borders">
      <template #avatar><q-icon name="lock" color="grey-8"/></template>
      Esta revisión está {{header.estado.toLowerCase()}} y ya no se puede modificar.
    </q-banner>

    <div class="row q-col-gutter-sm">
      <div v-if="editable" class="col-12 col-md-6">
        <q-card flat bordered>
          <q-card-section class="row q-col-gutter-sm q-pa-sm">
            <q-input ref="searchInput" v-model="search" dense outlined autofocus clearable class="col-12" placeholder="Buscar o escanear producto" @update:model-value="handleSearchInput" @keydown.enter.prevent="openExact($event.target.value)"><template #prepend><q-btn dense flat round icon="qr_code_scanner" color="primary" @click.stop="openScanner"><q-tooltip>Escanear con la cámara</q-tooltip></q-btn></template></q-input>
            <q-select v-model="category" :options="categories" option-label="nombre" dense outlined clearable label="Categoría" class="col-12 col-sm-6" @update:model-value="resetProductsPage"/>
            <q-select v-model="sortOrder" :options="sortOptions" emit-value map-options dense outlined label="Ordenar por" class="col-12 col-sm-6" @update:model-value="changeSort"><template #prepend><q-icon name="sort" size="18px"/></template></q-select>
          </q-card-section>
          <q-separator/>
          <q-card-section v-if="loadingProducts" class="flex flex-center product-loading"><q-spinner color="primary" size="38px"/></q-card-section>
          <q-card-section v-else-if="!products.length" class="text-center text-grey-6 q-py-xl"><q-icon name="search_off" size="42px"/><div>Sin productos para esta búsqueda</div></q-card-section>
          <div v-else class="product-grid">
            <table class="product-table">
              <thead><tr><th class="c-img"/><th v-for="col in sortColumns" :key="col.field" :class="[col.cls,'th-sort',{'th-active':sortField===col.field}]" @click="sortByColumn(col.field)">{{col.label}}<q-icon :name="sortField===col.field?(sortDir==='desc'?'arrow_downward':'arrow_upward'):'unfold_more'" size="12px" class="q-ml-xs"/></th><th class="c-stock">Contado</th><th class="c-stock">Dif.</th><th class="c-done"/></tr></thead>
              <tbody>
                <tr v-for="product in products" :key="product.id" class="cursor-pointer" :class="{'row-done':countedIds.has(product.id)}" @click="openCount(product)">
                  <td class="c-img"><q-avatar rounded size="26px" color="grey-2"><img v-if="product.foto" :src="photoUrl(product.foto)"/><q-icon v-else name="inventory_2" size="15px" color="grey-5"/></q-avatar></td>
                  <td class="c-code">{{product.codigo}}</td>
                  <td class="c-name">{{product.nombre}}</td>
                  <td class="c-unit">{{product.unidad}}</td>
                  <td class="c-stock">{{qty(countedOf(product)?systemStock(countedOf(product)):product.stock_inicial,product.unidad)}}</td>
                  <td class="c-stock c-counted">{{countedOf(product)?qty(countedOf(product).cantidad,product.unidad):'—'}}</td>
                  <td class="c-stock"><span v-if="countedOf(product)" :class="`text-${diffColor(countedOf(product))}`">{{diffLabel(countedOf(product))}}</span><span v-else class="text-grey-5">—</span></td>
                  <td class="c-done"><q-icon v-if="countedIds.has(product.id)" name="task_alt" color="positive" size="16px"><q-tooltip>Ya contado</q-tooltip></q-icon></td>
                </tr>
              </tbody>
            </table>
          </div>
          <q-separator/>
          <q-card-actions class="row items-center justify-between q-px-sm">
            <div class="row items-center no-wrap q-gutter-x-sm">
              <q-select v-model="productsPerPage" :options="perPageOptions" emit-value map-options dense outlined options-dense class="per-page" @update:model-value="changePerPage"/>
              <span class="text-caption text-grey-7">{{productsFrom}}–{{productsTo}} de {{productsTotal}}</span>
            </div>
            <q-pagination v-if="productsLastPage>1" v-model="productsPage" :max="productsLastPage" :max-pages="5" boundary-numbers direction-links color="primary" size="sm" @update:model-value="loadProducts"/>
          </q-card-actions>
        </q-card>
      </div>

      <div class="col-12" :class="editable?'col-md-6':''">
        <q-card flat bordered>
          <q-card-section class="row items-center q-py-sm">
            <q-icon name="fact_check" color="primary" size="22px" class="q-mr-xs"/><b>Productos revisados</b>
            <q-space/>
            <span class="text-caption text-grey-6 q-mr-sm">{{refreshedLabel}}</span>
            <q-btn dense flat round size="sm" icon="refresh" :loading="refreshing" @click="loadItems()"><q-tooltip>Ver lo que cargaron los demás</q-tooltip></q-btn>
            <q-badge color="primary" :label="itemsTotal"/>
          </q-card-section>
          <q-separator/>
          <q-card-section class="q-pa-sm"><q-input v-model="itemsSearch" dense outlined clearable placeholder="Buscar en lo revisado (producto, código o quién contó)" @update:model-value="handleItemsSearch"><template #prepend><q-icon name="search"/></template></q-input></q-card-section>
          <q-separator/>
          <q-list v-if="items.length" separator class="count-list">
            <q-item v-for="item in items" :key="item.id" dense class="q-px-sm">
              <q-item-section avatar class="count-thumb"><q-avatar rounded size="30px" color="grey-2"><img v-if="item.foto" :src="photoUrl(item.foto)"/><q-icon v-else name="inventory_2" size="16px"/></q-avatar></q-item-section>
              <q-item-section>
                <q-item-label lines="1" class="text-caption text-weight-bold">{{item.nombre}}</q-item-label>
                <q-item-label caption class="count-meta">
                  Sistema {{qty(systemStock(item),item.unidad)}} · Total contado <b>{{qty(item.cantidad,item.unidad)}}</b>
                </q-item-label>
                <q-item-label v-if="item.conteos?.length" caption class="count-lots">
                  <q-badge v-for="lot in item.conteos" :key="lot.id" outline color="deep-orange" class="q-mr-xs"
                           :label="`${lot.lote||'sin lote'} · ${qty(lot.cantidad,item.unidad)}${lot.fecha_vencimiento?' · vence '+shortDate(lot.fecha_vencimiento):''}`"/>
                </q-item-label>
                <q-item-label caption class="count-users">
                  <q-badge v-for="u in item.usuarios||[]" :key="u.id" :color="isMine(u)?'primary':'grey-3'" :text-color="isMine(u)?'white':'grey-9'" class="q-mr-xs"
                           :label="`${isMine(u)?'TÚ':u.usuario_nombre} ${qty(u.cantidad,item.unidad)}`"/>
                  <span v-if="!(item.usuarios||[]).length" class="text-grey-6">Contó {{item.usuario_nombre||'—'}}</span>
                </q-item-label>
              </q-item-section>
              <q-item-section side>
                <div class="row items-center no-wrap">
                  <q-badge :color="diffColor(item)" :label="diffLabel(item)" class="q-mr-xs"/>
                  <q-btn v-if="editable" dense flat round size="sm" :icon="myCount(item)?'edit':'add'" color="primary" @click="openCount(null,item)"><q-tooltip>{{myCount(item)?'Corregir tu conteo':'Agregar tu conteo'}}</q-tooltip></q-btn>
                  <q-btn v-if="editable" dense flat round size="sm" icon="delete" color="negative" @click="removeItem(item)"><q-tooltip>{{myCount(item)?'Quitar tu conteo':'Quitar el producto'}}</q-tooltip></q-btn>
                </div>
              </q-item-section>
            </q-item>
          </q-list>
          <q-card-section v-else class="text-center text-grey-6 q-py-xl"><q-icon name="inventory" size="42px"/><div>{{itemsSearch?'Sin resultados en lo revisado':'Todavía no se contó ningún producto'}}</div></q-card-section>
          <q-separator/>
          <q-card-actions v-if="itemsLastPage>1" class="row items-center justify-between q-px-sm">
            <span class="text-caption text-grey-7">{{itemsFrom}}–{{itemsTo}} de {{itemsTotal}}</span>
            <q-pagination v-model="itemsPage" :max="itemsLastPage" :max-pages="5" boundary-numbers direction-links color="primary" size="sm" @update:model-value="loadItems()"/>
          </q-card-actions>
          <q-separator v-if="itemsLastPage>1"/>
          <q-card-actions class="q-pa-sm">
            <q-btn class="full-width" color="primary" unelevated icon="insights" label="Ver avance y actualizar productos" no-caps :to="`/inventario/${id}/avance`"/>
          </q-card-actions>
        </q-card>
      </div>
    </div>

    <q-dialog v-model="countDialog" @hide="focusSearch">
      <q-card style="width:430px;max-width:94vw">
        <q-form @submit.prevent="saveCount">
          <q-card-section class="row items-center q-py-sm bg-primary text-white">
            <q-avatar rounded color="white" text-color="primary" size="38px"><img v-if="form.foto" :src="photoUrl(form.foto)"/><q-icon v-else name="inventory_2"/></q-avatar>
            <div class="q-ml-sm col"><div class="text-subtitle1 text-weight-bold ellipsis">{{form.nombre}}</div><div class="text-caption">{{form.codigo}} · {{form.unidad}}</div></div>
            <q-btn flat round dense icon="close" color="white" v-close-popup/>
          </q-card-section>
          <q-card-section class="row q-col-gutter-sm q-pa-md">
            <div class="col-12 row items-center q-pa-sm rounded-borders bg-blue-grey-1">
              <div><div class="text-caption text-grey-7">Stock del sistema</div><div class="text-h6 text-weight-bold">{{qty(form.stock_sistema,form.unidad)}}</div></div>
              <q-space/>
              <div class="text-right"><div class="text-caption text-grey-7">Diferencia</div><div v-if="quantityEntered" class="text-h6 text-weight-bold" :class="formDifference>0?'text-positive':formDifference<0?'text-negative':'text-grey-7'">{{formDifference>0?'+':''}}{{formDifference.toFixed(form.unidad==='KG'?3:0)}}</div><div v-else class="text-h6 text-weight-bold text-grey-5">—</div></div>
            </div>
            <div v-if="form.otros.length" class="col-12 q-pa-sm rounded-borders others-box">
              <div class="text-caption text-weight-bold text-grey-8 q-mb-xs"><q-icon name="groups" size="16px"/> Ya contaron este producto (se suma con lo tuyo)</div>
              <div v-for="o in form.otros" :key="o.id" class="row items-center text-caption"><q-icon name="person" size="14px" class="q-mr-xs" color="grey-7"/>{{o.usuario_nombre}}<q-space/><b>{{qty(o.cantidad,form.unidad)}}</b></div>
            </div>
            <q-input v-if="!form.conteos.length" ref="quantityInput" v-model.number="form.cantidad" autofocus outlined dense type="number" min="0" :step="form.unidad==='KG'?0.001:1" :label="form.otros.length?'Lo que contaste tú *':'Cantidad contada *'" placeholder="¿Cuánto hay en la tienda?" class="col-12" input-class="text-h5 text-weight-bold" @focus="$event.target.select()"><template #prepend><q-icon name="scale"/></template></q-input>
            <div v-else class="col-12 row items-center q-pa-sm rounded-borders bg-orange-1 text-orange-10"><span>Lo que contaste tú (suma de los lotes)</span><q-space/><b class="text-h6">{{qty(form.cantidad,form.unidad)}}</b></div>
            <div v-if="form.otros.length" class="col-12 row items-center q-pa-sm rounded-borders bg-blue-1 text-blue-10"><span>Total del producto (todos)</span><q-space/><b class="text-h6">{{qty(formTotal,form.unidad)}}</b></div>

            <div class="col-12">
              <div class="row items-center q-mb-xs"><div class="text-caption text-weight-bold text-grey-8">Lotes y vencimientos</div><q-space/><q-btn dense flat no-caps size="sm" color="primary" icon="add" label="Agregar lote" @click="addLot"/></div>
              <div v-if="!form.conteos.length" class="text-caption text-grey-6">Sin lotes: se guarda sólo la cantidad total. Agrega lotes si necesitas registrar vencimientos.</div>
              <div v-for="(lot,index) in form.conteos" :key="index" class="lot-row">
                <input v-model.trim="lot.lote" class="lot-input" placeholder="Lote">
                <input v-model="lot.fecha_vencimiento" class="lot-input date" type="date">
                <input v-model.number="lot.cantidad" class="lot-input qty" type="number" min="0" step="any" placeholder="Cant." @input="syncFormQuantity">
                <q-btn dense flat round size="xs" icon="delete" color="negative" @click="removeLot(index)"/>
              </div>
            </div>

            <q-input v-model.trim="form.observacion" outlined dense label="Observación (opcional)" class="col-12" maxlength="255"/>
          </q-card-section>
          <q-separator/>
          <q-card-actions align="right" class="q-pa-sm">
            <q-btn flat dense label="Cancelar" no-caps v-close-popup/>
            <q-btn type="submit" dense unelevated color="positive" icon="save" label="Guardar producto" no-caps :loading="savingLine"/>
          </q-card-actions>
        </q-form>
      </q-card>
    </q-dialog>

    <q-dialog v-model="scannerDialog" maximized-on-mobile @show="startScanner" @hide="stopScanner">
      <q-card style="width:480px;max-width:100vw">
        <q-card-section class="row items-center q-py-sm bg-primary text-white">
          <q-icon name="qr_code_scanner" size="22px" class="q-mr-sm"/><b>Escanear producto</b>
          <q-space/>
          <q-btn flat round dense icon="close" color="white" v-close-popup/>
        </q-card-section>
        <q-card-section class="q-pa-sm">
          <div id="inventario-scanner" class="scanner-box"/>
          <div v-if="scannerError" class="text-negative text-center q-mt-sm">{{scannerError}}</div>
          <div v-else class="text-caption text-grey-7 text-center q-mt-sm">Apunta la cámara al código QR o de barras del producto</div>
        </q-card-section>
      </q-card>
    </q-dialog>
  </q-page>
</template>

<script setup>
import { computed, getCurrentInstance, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { Html5Qrcode } from 'html5-qrcode'
const {proxy}=getCurrentInstance(),route=useRoute(),router=useRouter()
const id=Number(route.params.id)
const header=reactive({numero:'',estado:'BORRADOR',descripcion:'',observacion:''})
const items=ref([]),refreshing=ref(false),refreshedAt=ref(null),savingLine=ref(false)
// Lo revisado viaja paginado: con cientos de productos el documento completo pesaba ~380 KB por consulta.
const itemsPage=ref(1),itemsLastPage=ref(1),itemsTotal=ref(0),itemsFrom=ref(0),itemsTo=ref(0),itemsSearch=ref(''),itemsPerPage=20
const countedByProduct=ref({})
const products=ref([]),categories=ref([]),search=ref(''),category=ref(null),searchInput=ref(null),loadingProducts=ref(false)
const productsPage=ref(1),productsLastPage=ref(1),productsTotal=ref(0),productsFrom=ref(0),productsTo=ref(0)
// Productos por página: "Todos" pide el máximo que entrega el API (500); si hay más, sigue la paginación.
const PER_PAGE_KEY='inventarioPorPaginaBajoCero',perPageOptions=[{label:'20 por página',value:20},{label:'50 por página',value:50},{label:'100 por página',value:100},{label:'Todos',value:500}]
const productsPerPage=ref(perPageOptions.some(o=>o.value===Number(localStorage.getItem(PER_PAGE_KEY)))?Number(localStorage.getItem(PER_PAGE_KEY)):20)
const countDialog=ref(false),scannerDialog=ref(false),scannerError=ref('')
const form=reactive({detalle_id:null,producto_id:null,codigo:'',nombre:'',unidad:'UNIDAD',foto:null,stock_sistema:0,cantidad:0,observacion:'',conteos:[],otros:[]})
let productsSearchTimer=null,itemsSearchTimer=null,refreshTimer=null,scanner=null,scanHandled=false
const photoUrl=path=>`${proxy.$imgBase}/images/${path}`
const qty=(value,unit)=>Number(value||0).toFixed(unit==='KG'?3:0)
const shortDate=value=>value?new Date(`${String(value).slice(0,10)}T12:00:00`).toLocaleDateString('es-BO'):''
const editable=computed(()=>header.estado==='BORRADOR')
const stateColor=computed(()=>header.estado==='APLICADO'?'positive':header.estado==='ANULADO'?'grey-6':'orange')
// Las marcas de "ya contado" de la grilla se piden sólo para los productos de la página visible.
const countedOf=p=>countedByProduct.value[p.id]||items.value.find(i=>i.producto_id===p.id)||null
const countedIds=computed(()=>new Set([...Object.keys(countedByProduct.value).map(Number),...items.value.map(i=>i.producto_id)]))
const refreshedLabel=computed(()=>refreshedAt.value?`Actualizado ${refreshedAt.value.toLocaleTimeString('es-BO')}`:'')
// stock_actual lo calcula el backend con el stock vivo del producto (o el guardado al contar si ya no existe).
const systemStock=item=>Number(item.stock_actual??item.stock_sistema??0)
const difference=item=>Number((Number(item.cantidad||0)-systemStock(item)).toFixed(3))
const diffLabel=item=>{const d=difference(item);return `${d>0?'+':''}${d.toFixed(item.unidad==='KG'?3:0)}`}
const diffColor=item=>{const d=difference(item);return Math.abs(d)<0.0005?'grey-6':d>0?'positive':'negative'}
const quantityEntered=computed(()=>form.cantidad!==null&&form.cantidad!=='')
// Cada persona guarda su propio conteo; el producto suma lo de todos y eso se compara con el sistema.
const meId=computed(()=>proxy.$store.user?.id)
const isMine=u=>u.user_id&&u.user_id===meId.value
const myCount=item=>(item.usuarios||[]).find(isMine)||null
const formTotal=computed(()=>Number((form.otros.reduce((s,o)=>s+Number(o.cantidad||0),0)+Number(form.cantidad||0)).toFixed(3)))
const formDifference=computed(()=>Number((formTotal.value-Number(form.stock_sistema||0)).toFixed(3)))

async function loadProducts(){
  loadingProducts.value=true
  try{
    const [sortBy,sortDir]=sortOrder.value.split(':')
    const {data}=await proxy.$axios.get('/productos',{params:{q:search.value,categoria_id:category.value?.id,sort_by:sortBy,sort_dir:sortDir,per_page:productsPerPage.value,page:productsPage.value}})
    products.value=data.data;productsLastPage.value=data.last_page||1;productsTotal.value=data.total||0;productsFrom.value=data.from||0;productsTo.value=data.to||0
    loadCounted()
  }catch(e){proxy.$alert.error(e.response?.data?.message||'No se pudieron cargar los productos')}
  finally{loadingProducts.value=false}
}
async function loadCounted(){
  const ids=products.value.map(p=>p.id);if(!ids.length){countedByProduct.value={};return}
  // El API devuelve hasta 100 detalles por consulta: con 500 productos en pantalla se pide por bloques de 100.
  try{
    const chunks=[];for(let i=0;i<ids.length;i+=100)chunks.push(ids.slice(i,i+100))
    const pages=await Promise.all(chunks.map(c=>proxy.$axios.get(`/almacenes/${id}/detalles`,{params:{producto_ids:c,per_page:100}})))
    countedByProduct.value=Object.fromEntries(pages.flatMap(r=>r.data.data||[]).map(d=>[d.producto_id,d]))
  }catch(e){/* sólo son las marcas de la grilla */}
}
async function loadItems(silent=false){
  if(!silent)refreshing.value=true
  try{
    const {data}=await proxy.$axios.get(`/almacenes/${id}/detalles`,{params:{q:itemsSearch.value||undefined,page:itemsPage.value,per_page:itemsPerPage}})
    if(data.last_page&&itemsPage.value>data.last_page){itemsPage.value=data.last_page;return loadItems(silent)}
    items.value=data.data||[];itemsLastPage.value=data.last_page||1;itemsTotal.value=data.total||0;itemsFrom.value=data.from||0;itemsTo.value=data.to||0
    if(data.estado)header.estado=data.estado
    refreshedAt.value=new Date()
  }catch(e){if(!silent)proxy.$alert.error(e.response?.data?.message||'No se pudo cargar lo revisado')}
  finally{refreshing.value=false}
}
function handleItemsSearch(){clearTimeout(itemsSearchTimer);itemsSearchTimer=setTimeout(()=>{itemsPage.value=1;loadItems()},300)}
function afterChange(){loadItems(true);loadCounted()}
function resetProductsPage(){productsPage.value=1;loadProducts()}
// Orden de la tabla para contar: se recuerda en el equipo para que cada persona siga con su forma de recorrer la tienda.
const SORT_KEY='inventarioOrdenBajoCero'
const sortOptions=[
  {label:'Nombre (A → Z)',value:'nombre:asc'},{label:'Nombre (Z → A)',value:'nombre:desc'},{label:'Código',value:'codigo:asc'},
  {label:'Categoría',value:'categoria:asc'},{label:'Unidad (KG juntos)',value:'unidad:asc'},
  {label:'Mayor stock primero',value:'stock_inicial:desc'},{label:'Menor stock primero',value:'stock_inicial:asc'},
  {label:'Precio venta: mayor primero',value:'precio_venta:desc'},{label:'Precio venta: menor primero',value:'precio_venta:asc'},
  {label:'Costo: mayor primero',value:'precio_compra:desc'},{label:'Más recientes',value:'created_at:desc'}
]
const sortOrder=ref(sortOptions.some(o=>o.value===localStorage.getItem(SORT_KEY))?localStorage.getItem(SORT_KEY):'nombre:asc')
function changePerPage(value){localStorage.setItem(PER_PAGE_KEY,value);resetProductsPage()}
// Clic en el encabezado: ordena por esa columna; otro clic invierte el sentido. Queda sincronizado con "Ordenar por".
const sortColumns=[{field:'codigo',label:'Código',cls:'c-code'},{field:'nombre',label:'Producto',cls:''},{field:'unidad',label:'Unidad',cls:'c-unit'},{field:'stock_inicial',label:'Sistema',cls:'c-stock'}]
const sortField=computed(()=>sortOrder.value.split(':')[0]),sortDir=computed(()=>sortOrder.value.split(':')[1])
function sortByColumn(field){const value=sortField.value===field?`${field}:${sortDir.value==='asc'?'desc':'asc'}`:`${field}:${field==='stock_inicial'?'desc':'asc'}`;sortOrder.value=value;changeSort(value)}
function changeSort(value){localStorage.setItem(SORT_KEY,value);resetProductsPage()}
function handleSearchInput(){clearTimeout(productsSearchTimer);productsSearchTimer=setTimeout(resetProductsPage,250)}
function focusSearch(){setTimeout(()=>searchInput.value?.focus(),50)}
async function openExact(value){
  const code=String(value??search.value??'').trim().toUpperCase();if(!code)return
  const match=p=>String(p.codigo||'').toUpperCase()===code||String(p.codigo_barras||'').toUpperCase()===code
  let product=products.value.find(match)
  if(!product){const {data}=await proxy.$axios.get('/productos',{params:{q:code,per_page:20,page:1}});product=(data.data||[]).find(match)}
  if(!product)return proxy.$alert.error(`No existe un producto con código ${code}`)
  search.value='';resetProductsPage();openCount(product)
}
// Abre el conteo desde la grilla (producto) o desde una línea ya cargada (detalle).
// Se edita sólo el conteo propio (cantidad, lotes y vencimientos); lo de los demás se muestra aparte.
function openCount(product,detail=null){
  if(!editable.value)return
  const counted=detail||countedByProduct.value[product.id]||items.value.find(i=>i.producto_id===product.id)
  const mine=counted?myCount(counted):null
  Object.assign(form,{
    detalle_id:counted?.id||null,
    producto_id:counted?.producto_id||product.id,
    codigo:counted?.codigo||product.codigo,
    nombre:counted?.nombre||product.nombre,
    unidad:counted?.unidad||product.unidad,
    foto:counted?.foto??product?.foto??null,
    stock_sistema:counted?systemStock(counted):Number(product.stock_inicial||0),
    // Si todavía no contaste este producto arrancas en blanco: se escribe lo que realmente hay en tu sector.
    cantidad:mine?Number(mine.cantidad):null,
    observacion:mine?.observacion||'',
    conteos:(mine?.conteos||[]).map(c=>({lote:c.lote||'',fecha_vencimiento:c.fecha_vencimiento?String(c.fecha_vencimiento).slice(0,10):'',cantidad:Number(c.cantidad)})),
    otros:(counted?.usuarios||[]).filter(u=>!isMine(u))
  })
  countDialog.value=true
}
function addLot(){
  // El primer lote arranca con lo que ya estaba contado para no perder el dato.
  const pending=form.conteos.length?0:Number(form.cantidad||0)
  form.conteos.push({lote:'',fecha_vencimiento:'',cantidad:pending})
  syncFormQuantity()
}
function removeLot(index){form.conteos.splice(index,1);syncFormQuantity()}
function syncFormQuantity(){
  if(form.conteos.length)form.cantidad=Number(form.conteos.reduce((sum,l)=>sum+Number(l.cantidad||0),0).toFixed(3))
}
function linePayload(extra={}){
  return {producto_id:form.producto_id,cantidad:Number(form.cantidad||0),observacion:form.observacion||null,
    conteos:form.conteos.map(l=>({lote:l.lote||null,fecha_vencimiento:l.fecha_vencimiento||null,cantidad:Number(l.cantidad||0)})),...extra}
}
async function saveCount(){
  syncFormQuantity()
  if(form.conteos.some(l=>!(Number(l.cantidad)>0)))return proxy.$alert.error('Cada lote necesita una cantidad mayor a cero')
  if(!quantityEntered.value||!(Number(form.cantidad)>=0))return proxy.$alert.error('Ingresa la cantidad contada')
  savingLine.value=true
  try{
    // El backend guarda (o reemplaza) sólo el conteo de quien envía; lo de los demás se suma.
    const {data}=await proxy.$axios.post(`/almacenes/${id}/detalles`,linePayload())
    proxy.$alert.success(`${form.nombre} registrado`,(data.usuarios||[]).length>1?`Total entre ${data.usuarios.length} personas: ${qty(data.cantidad,form.unidad)}`:'')
    countDialog.value=false
    afterChange()
  }catch(e){
    proxy.$alert.error(Object.values(e.response?.data?.errors||{})[0]?.[0]||e.response?.data?.message||'No se pudo guardar el producto')
  }finally{savingLine.value=false}
}
// Quita tu conteo (lo de los demás queda); si no contaste ese producto, quita el producto completo.
function removeItem(item){
  const mine=myCount(item),others=(item.usuarios||[]).filter(u=>!isMine(u)).length
  const message=mine
    ?`¿Quitar tu conteo de ${item.nombre} (${qty(mine.cantidad,item.unidad)})?${others?' Lo que contaron los demás se mantiene.':''}`
    :`¿Quitar ${item.nombre} de la revisión?${others?` Se borran los conteos de ${others} persona${others===1?'':'s'}.`:''}`
  proxy.$alert.dialog(message).onOk(async()=>{
    try{
      await proxy.$axios.delete(mine?`/almacenes/${id}/detalles/${item.id}/usuarios/${mine.id}`:`/almacenes/${id}/detalles/${item.id}`)
      proxy.$alert.success(mine?'Tu conteo fue quitado':'Producto quitado');afterChange()
    }catch(e){proxy.$alert.error(e.response?.data?.message||'No se pudo quitar')}
  })
}
function openScanner(){scannerError.value='';scanHandled=false;scannerDialog.value=true}
// Usa la cámara trasera del celular; al leer un código busca el producto y abre su conteo.
async function startScanner(){
  try{
    scanner=new Html5Qrcode('inventario-scanner')
    await scanner.start({facingMode:'environment'},{fps:10,qrbox:(w,h)=>{const size=Math.floor(Math.min(w,h)*0.75);return {width:size,height:size}}},
      async text=>{
        if(scanHandled)return
        scanHandled=true
        if(navigator.vibrate)navigator.vibrate(80)
        scannerDialog.value=false
        await openExact(text)
      },()=>{})
  }catch(e){
    scannerError.value=window.isSecureContext?'No se pudo abrir la cámara. Revisa que el navegador tenga permiso para usarla.':'La cámara sólo funciona con https.'
    scanner=null
  }
}
async function stopScanner(){
  const current=scanner;scanner=null
  if(!current)return
  try{if(current.isScanning)await current.stop();current.clear()}catch(e){}
}
// La cabecera (sin detalle) se pide una sola vez; el estado se sigue actualizando con cada página de loadItems.
async function loadAlmacen(){
  try{
    const {data}=await proxy.$axios.get(`/almacenes/${id}`)
    Object.assign(header,{numero:data.numero,estado:data.estado,descripcion:data.descripcion||'',observacion:data.observacion||''})
  }catch(e){
    proxy.$alert.error(e.response?.data?.message||'No se pudo cargar la revisión')
    if(e.response?.status===404)router.replace('/inventario')
  }
}
onMounted(()=>{
  loadAlmacen();loadItems();loadProducts()
  proxy.$axios.get('/productos-catalogos').then(r=>categories.value=r.data.categorias).catch(()=>{})
  // Varias personas cargan a la vez: la página visible de la lista se refresca sola mientras la pestaña está visible.
  refreshTimer=setInterval(()=>{if(!document.hidden&&!countDialog.value&&editable.value)loadItems(true)},15000)
})
onBeforeUnmount(()=>{clearTimeout(productsSearchTimer);clearTimeout(itemsSearchTimer);clearInterval(refreshTimer);stopScanner()})
</script>

<style scoped>
.product-loading{min-height:280px}
.product-grid{max-height:calc(100vh - 300px);overflow:auto}.per-page{width:130px}
.th-sort{cursor:pointer;user-select:none;white-space:nowrap}.product-table th.th-sort:hover{color:#e65100;background:#ffe0b2}.th-sort .q-icon{opacity:.45}.product-table th.th-active{color:#e65100}.th-active .q-icon{opacity:1}.per-page :deep(.q-field__native){font-size:12px}
.product-table{width:100%;border-collapse:collapse;table-layout:fixed;font-size:12px}
.product-table th{position:sticky;top:0;z-index:1;background:#f5f5f5;color:#616161;font-size:10px;font-weight:600;text-transform:uppercase;text-align:left;padding:4px 6px;border-bottom:1px solid #e0e0e0}
.product-table td{padding:2px 6px;border-bottom:1px solid #eee;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;height:32px}
.product-table tbody tr:nth-child(even){background:#fafafa}.product-table tbody tr:hover{background:#fff3e0}.product-table tr.row-done{background:#f1f8f2}
.c-img{width:38px}.c-code{width:84px;color:#616161}.product-table td.c-name{font-weight:600;font-size:10px;line-height:.8;white-space:normal;overflow:visible;text-overflow:clip;word-break:break-word;padding-top:3px;padding-bottom:3px}.c-unit{width:62px;color:#616161}.c-stock{width:70px;text-align:right!important;font-weight:700}.c-counted{color:#1565c0}.c-done{width:28px;text-align:center}
.count-list{max-height:calc(100vh - 250px);overflow:auto}.count-thumb{min-width:38px;padding-right:6px}.count-meta{font-size:11px;line-height:14px}
.count-lots{margin-top:2px;line-height:16px}
.count-users{margin-top:2px;line-height:18px}
.others-box{background:#f5f7fa;border:1px dashed #cfd8dc}
.scanner-box{width:100%;min-height:260px;background:#000;border-radius:6px;overflow:hidden}
.lot-row{display:flex;align-items:center;gap:4px;margin-bottom:4px}
.lot-input{height:28px;border:1px solid #cfd8dc;border-radius:4px;padding:2px 6px;font-size:12px;color:#263238;background:#fff;min-width:0;flex:1 1 auto}
.lot-input.date{flex:0 0 128px}.lot-input.qty{flex:0 0 86px;text-align:right;font-weight:700}
.lot-input:focus{outline:1px solid #f57c00;border-color:#f57c00}
@media(max-width:1023px){.product-grid{max-height:none}.count-list{max-height:none}}
</style>
