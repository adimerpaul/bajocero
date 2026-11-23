<template>
  <q-page class="q-pa-sm">
    <div class="row items-center q-mb-sm"><div><div class="text-subtitle1 text-weight-bold">Nueva compra</div><div class="text-caption text-grey-7">Agrega mercadería al inventario</div></div><q-space/><q-btn flat dense icon="groups" label="Proveedores" no-caps to="/proveedores"/><q-btn flat dense icon="shopping_bag" label="Ver compras" no-caps to="/compras"/></div>
    <div class="row q-col-gutter-sm">
      <div class="col-12 col-lg-5">
        <q-card flat bordered>
          <q-card-section class="row q-col-gutter-sm q-pa-sm"><q-input ref="searchInput" v-model="search" dense outlined autofocus clearable class="col" placeholder="Buscar o escanear producto · Enter agrega" @update:model-value="onSearchInput" @keydown.enter.prevent="addExact"><template #append><q-spinner v-if="loadingProducts" size="16px" color="primary"/></template><template #prepend><q-icon name="qr_code_scanner"/></template></q-input></q-card-section>
          <q-separator/><q-card-section class="q-pa-xs product-grid"><q-card v-for="product in products" :key="product.id" flat bordered class="product-card cursor-pointer" @click="add(product)"><div class="product-image"><img v-if="product.foto" :src="photoUrl(product.foto)"/><q-icon v-else name="inventory_2" size="20px" color="grey-4"/></div><div class="q-px-xs q-pb-xs"><div class="product-name text-weight-bold">{{product.nombre}}</div><div class="row items-center product-meta"><span class="text-grey-8">C {{money(product.precio_compra)}}</span><q-space/><b class="text-primary">V {{money(product.precio_venta)}}</b></div><div class="product-meta text-grey-7 ellipsis">Stock {{qty(product.stock_inicial,product.unidad)}} {{product.unidad}}</div></div><q-tooltip :delay="300" anchor="top middle" self="bottom middle" class="bg-grey-10 product-tip"><div class="text-weight-bold">{{product.nombre}}</div><div>Código: {{product.codigo}}</div><div v-if="product.codigo_barras">Cód. barras: {{product.codigo_barras}}</div><div v-if="product.categoria">Categoría: {{product.categoria}}</div><div>Unidad: {{product.unidad}}</div><div>Stock: {{qty(product.stock_inicial,product.unidad)}} {{product.unidad}}</div><div>Costo: Bs {{money(product.precio_compra)}}</div><div>Venta: Bs {{money(product.precio_venta)}}</div></q-tooltip></q-card><div v-if="!products.length&&!loadingProducts" class="text-center text-grey-6 q-pa-md" style="grid-column:1/-1">Sin productos</div></q-card-section>
          <q-separator/>
          <q-card-section class="row items-center q-py-xs q-px-sm"><div class="text-caption text-grey-7">{{totalProducts}} productos · página {{page}} de {{lastPage}}</div><q-space/><q-pagination v-model="page" :max="lastPage" :max-pages="5" boundary-numbers dense size="sm" color="primary" @update:model-value="loadProducts()"/></q-card-section>
        </q-card>
      </div>
      <div class="col-12 col-lg-7">
        <q-card flat bordered class="purchase-card">
          <q-tabs v-model="tabCarrito" dense no-caps align="left" class="cart-tabs bg-grey-2 text-grey-8" active-color="primary" active-bg-color="white" indicator-color="primary" narrow-indicator :breakpoint="0">
            <q-tab v-for="c in carritos" :key="c.id" :name="c.id" class="cart-tab" @dblclick="renombrarCarrito(c)">
              <div class="row items-center no-wrap">
                <q-icon name="add_business" size="15px" class="q-mr-xs"/><span class="ellipsis">{{c.nombre}}</span>
                <q-badge v-if="c.items.length" color="primary" class="q-ml-xs" :label="c.items.length"/>
                <q-btn v-if="carritos.length>1" dense flat round size="xs" icon="close" class="q-ml-xs" @click.stop="cerrarCarrito(c)"/>
              </div>
              <q-tooltip :delay="400" anchor="top middle" self="bottom middle" class="bg-grey-10">{{c.items.length}} ítem(s) · Bs {{money(cartTotal(c))}} · doble clic para renombrar</q-tooltip>
            </q-tab>
            <q-btn dense flat no-caps size="sm" icon="add" color="primary" class="q-ml-xs" :disable="carritos.length>=MAX_CARRITOS" @click="nuevoCarrito"><q-tooltip>Nueva compra en espera ({{carritos.length}}/{{MAX_CARRITOS}})</q-tooltip></q-btn>
          </q-tabs>
          <q-separator/>
          <q-card-section class="row items-center q-py-sm"><q-icon name="add_business" color="primary" size="22px" class="q-mr-xs"/><b class="ellipsis">{{carrito.nombre}}</b><q-space/><span class="text-caption text-grey-7 q-mr-sm">{{items.length}} ítem(s)</span><q-btn dense flat no-caps size="sm" icon="delete_sweep" color="negative" label="Limpiar" :disable="!items.length" @click="clearCart"/></q-card-section><q-separator/>
          <template v-if="items.length">
            <div class="cart-head"><span class="f-img"/><span class="cart-name">Producto</span><span class="f-qty text-center">Cant</span><span class="f-cost text-center">Costo</span><span class="f-sale text-center">Venta</span><span class="f-pct text-center">Gan. %</span><span class="f-total text-center">Total</span><span class="f-exp text-center">Vence</span><span class="f-lot text-center">Lote</span><span v-if="canEditProduct" class="f-del"/><span class="f-del"/></div>
            <div class="item-list">
              <div v-for="item in items" :key="item.id" class="cart-row">
                <q-avatar rounded size="30px" color="grey-2" class="f-img"><img v-if="item.foto" :src="photoUrl(item.foto)"/><q-icon v-else name="inventory_2" size="16px" color="grey-5"/></q-avatar>
                <div class="cart-name"><div class="ellipsis text-weight-bold">{{item.nombre}}</div><div class="cart-sub text-grey-7 ellipsis">{{item.codigo}} · {{item.unidad}}<span v-if="item.fecha_vencimiento" :class="daysUntil(item.fecha_vencimiento)<0?'text-negative':'text-orange-9'"> · {{expiryLabel(item.fecha_vencimiento)}}</span></div></div>
                <q-input v-model.number="item.cantidad" dense outlined type="number" min="0.001" step="0.001" class="f-qty" input-class="text-right" @update:model-value="syncItemTotal(item)"/>
                <q-input v-model.number="item.precio_unitario" dense outlined type="number" min="0" step="0.0001" class="f-cost" input-class="text-right" @update:model-value="syncItemTotal(item)"/>
                <q-input v-model.number="item.precio_venta" dense outlined type="number" min="0" step="0.01" class="f-sale" input-class="text-right"/>
                <q-input :model-value="marginText(item)" dense outlined type="number" step="0.1" debounce="700" class="f-pct" :class="marginOk(item)?'margin-ok':'margin-bad'" input-class="text-right text-weight-bold" @update:model-value="v=>applyMargin(item,v)"><q-tooltip :delay="300">{{marginOk(item)?'Ganancia dentro del rango':'La ganancia debe estar entre '+MARGIN_MIN+'% y '+MARGIN_MAX+'%'}} · costo Bs {{money(item.precio_unitario)}} → venta Bs {{money(item.precio_venta)}}</q-tooltip></q-input>
                <q-input v-model.number="item.total_editable" dense outlined type="number" min="0" step="0.01" class="f-total" input-class="text-right" @blur="applyItemTotal(item)"/>
                <q-input v-model="item.fecha_vencimiento" dense outlined type="date" class="f-exp"/>
                <q-input v-model="item.lote" dense outlined class="f-lot"/>
                <q-btn v-if="canEditProduct" dense flat round size="sm" icon="edit" color="primary" class="f-del" @click="openEdit(item)"><q-tooltip :delay="300">Editar producto</q-tooltip></q-btn>
                <q-btn dense flat round size="sm" icon="delete" color="negative" class="f-del" @click="remove(item)"/>
              </div>
            </div>
          </template>
          <q-card-section v-else class="text-center text-grey-6 q-py-lg">Agrega productos</q-card-section><q-separator/><q-card-section class="row text-h6 text-primary q-py-sm"><b>Total</b><q-space/><b>Bs {{money(total)}}</b></q-card-section><q-card-actions class="q-pa-sm"><q-btn class="full-width" color="positive" unelevated icon="save" label="Guardar compra" no-caps :disable="!items.length" @click="openCheckout"/></q-card-actions>
        </q-card>
      </div>
    </div>
    <q-dialog v-model="checkoutDialog"><q-card style="width:520px;max-width:94vw">
      <q-card-section class="row items-center q-py-sm"><q-icon name="add_business" color="primary" size="22px" class="q-mr-xs"/><b>Datos de compra</b><q-space/><q-btn flat round dense icon="close" v-close-popup/></q-card-section><q-separator/>
      <q-card-section class="q-pa-sm">
        <div class="row q-col-gutter-xs"><q-select v-model="provider" :options="providers" option-label="nombre" dense outlined label="Proveedor *" class="col"/><q-btn dense flat round color="primary" icon="person_add" @click="providerDialog=true"/></div>
        <q-card v-if="provider" flat bordered class="q-pa-sm q-mt-xs bg-red-1"><div class="text-weight-bold">{{provider.nombre}}</div><div class="text-caption">NIT: {{provider.nit||'Sin NIT'}} · Tel: {{provider.telefono||'Sin teléfono'}}</div><div v-if="provider.direccion" class="text-caption">{{provider.direccion}}</div></q-card>
        <q-input v-model="invoice" dense outlined label="Número de factura" class="q-mt-sm"/><q-select v-model="paymentType" :options="paymentTypes" dense outlined label="Tipo de pago *" class="q-mt-sm"/>
        <div v-if="paymentType==='COMBINADO'" class="row q-col-gutter-sm q-mt-xs"><q-input v-model.number="cashAmount" dense outlined type="number" min="0" step="0.01" label="Monto efectivo" prefix="Bs" class="col-6"/><q-input v-model.number="qrAmount" dense outlined type="number" min="0" step="0.01" label="Monto QR" prefix="Bs" class="col-6"/><div class="col-12 text-caption" :class="paymentDifference===0?'text-positive':'text-negative'">Diferencia: Bs {{money(paymentDifference)}}</div></div>
        <q-banner v-else dense rounded :class="paymentType==='EFECTIVO'?'bg-green-1 text-green-9':'bg-blue-1 text-blue-9'" class="q-mt-xs"><q-icon :name="paymentType==='EFECTIVO'?'payments':'qr_code_2'" class="q-mr-xs"/>Pago {{paymentType}}: Bs {{money(total)}}</q-banner>
        <q-input v-model="comment" dense outlined autogrow label="Comentario" class="q-mt-sm"/>
      </q-card-section><q-separator/>
      <q-card-section class="row text-h6 text-primary q-py-sm"><b>Total</b><q-space/><b>Bs {{money(total)}}</b></q-card-section>
      <q-card-actions class="q-pa-sm"><q-btn flat label="Cancelar" no-caps v-close-popup/><q-space/><q-btn color="positive" unelevated icon="save" label="Confirmar compra" no-caps :loading="saving" @click="save"/></q-card-actions>
    </q-card></q-dialog>
    <q-dialog v-model="providerDialog"><q-card style="width:460px;max-width:94vw"><q-form @submit="saveProvider"><q-card-section class="row"><b>Nuevo proveedor</b><q-space/><q-btn flat round dense icon="close" v-close-popup/></q-card-section><q-card-section class="q-gutter-sm"><q-input v-model="providerForm.nombre" dense outlined label="Nombre *"/><q-input v-model="providerForm.nit" dense outlined label="NIT"/><q-input v-model="providerForm.telefono" dense outlined label="Teléfono"/><q-input v-model="providerForm.direccion" dense outlined label="Dirección"/></q-card-section><q-card-actions align="right"><q-btn flat label="Cancelar" v-close-popup/><q-btn color="primary" label="Guardar" type="submit" :loading="providerSaving"/></q-card-actions></q-form></q-card></q-dialog>
    <q-dialog v-model="editDialog" persistent @hide="previewEdit(null)"><q-card style="width:620px;max-width:95vw"><q-form @submit="saveEdit">
      <q-card-section class="row items-center q-py-sm"><q-icon name="edit" color="primary" size="22px" class="q-mr-xs"/><b>Editar producto</b><q-space/><q-btn flat round dense icon="close" v-close-popup/></q-card-section><q-separator/>
      <q-card-section class="row q-col-gutter-sm q-pa-sm">
        <div class="col-12 col-sm-4 column items-center"><q-avatar rounded size="110px" color="grey-2"><img v-if="editPreview||editForm.foto" :src="editPreview||photoUrl(editForm.foto)"/><q-icon v-else name="add_photo_alternate" size="36px" color="grey-5"/></q-avatar><q-file v-model="editPhoto" dense borderless accept="image/*" label="Elegir fotografía" class="full-width q-mt-xs" @update:model-value="previewEdit"/><q-btn dense flat no-caps size="sm" icon="travel_explore" label="Buscar en Internet" @click="searchEditImage"/></div>
        <div class="col-12 col-sm-8 row q-col-gutter-sm">
          <q-input v-model="editForm.codigo" v-uppercase dense outlined label="Código *" class="col-12 col-sm-5" :rules="[v=>!!String(v||'').trim()||'Requerido']" hide-bottom-space/>
          <q-input v-model="editForm.codigo_barras" dense outlined label="Código de barras" class="col-12 col-sm-7"><template #prepend><q-icon name="qr_code_2"/></template></q-input>
          <q-input v-model="editForm.nombre" v-uppercase dense outlined label="Producto *" class="col-12" :rules="[v=>!!String(v||'').trim()||'Requerido']" hide-bottom-space/>
          <q-select v-model="editForm.categoria_id" :options="categorias" option-label="nombre" option-value="id" emit-value map-options clearable dense outlined label="Categoría" class="col-12 col-sm-7"/>
          <q-select v-model="editForm.unidad" :options="unitOptions" use-input new-value-mode="add-unique" dense outlined label="Unidad *" class="col-12 col-sm-5" :rules="[v=>!!v||'Requerido']" hide-bottom-space/>
          <q-input v-model="editForm.foto_url" dense outlined label="URL de imagen de Internet" class="col-12"><template #prepend><q-icon name="link"/></template></q-input>
          <div class="col-12 text-caption text-grey-7">Los cambios se guardan en el producto. Cantidad, costo, venta, vencimiento y lote se editan en la fila del carrito.</div>
        </div>
      </q-card-section>
      <q-card-actions align="right" class="q-pa-sm"><q-btn flat label="Cancelar" no-caps v-close-popup/><q-btn color="primary" unelevated icon="save" label="Guardar producto" no-caps type="submit" :loading="editSaving"/></q-card-actions>
    </q-form></q-card></q-dialog>
  </q-page>
</template>
<script setup>
import { computed, getCurrentInstance, onMounted, reactive, ref, watch } from 'vue'
import { printPurchase } from '../../addons/compraPrint'
import CompanyBanner from '../../components/CompanyBanner.vue'
import { MAX_CARRITOS, useCarritos } from '../../stores/carritos-store'
const {proxy}=getCurrentInstance(),products=ref([]),providers=ref([]),search=ref(''),saving=ref(false),providerSaving=ref(false),searchInput=ref(null),providerDialog=ref(false),checkoutDialog=ref(false)
// Hasta 5 compras en espera: cada pestaña guarda sus ítems y su cabecera en el store (y en localStorage).
const {lista:carritos,activoId,carrito,activar,agregar,cerrar,vaciar,renombrar,campo}=useCarritos('compras')
const items=campo('items'),provider=campo('proveedor'),invoice=campo('factura'),comment=campo('comentario'),paymentType=campo('tipoPago'),cashAmount=campo('efectivo'),qrAmount=campo('qr')
const tabCarrito=computed({get:()=>activoId.value,set:id=>{activar(id);searchInput.value?.focus()}})
const cartTotal=c=>(c?.items||[]).reduce((sum,i)=>sum+Number(i.total_editable||0),0)
function nuevoCarrito(){if(!agregar())return proxy.$alert.warning(`Solo puedes tener ${MAX_CARRITOS} compras en espera`);proxy.$alert.info(`${carrito.value.nombre} lista`);searchInput.value?.focus()}
function cerrarCarrito(c){if(!c.items.length)return cerrar(c.id);proxy.$alert.confirm(`¿Cerrar ${c.nombre}? Tiene ${c.items.length} ítem(s) sin guardar`).onOk(()=>cerrar(c.id))}
function renombrarCarrito(c){proxy.$alert.dialogPrompt(`Nuevo nombre para ${c.nombre}`).onOk(value=>renombrar(c.id,value))}
function clearCart(){if(!items.value.length)return;proxy.$alert.confirm(`¿Vaciar ${carrito.value.nombre}?`).onOk(()=>{vaciar();proxy.$alert.info('Compra vacía');searchInput.value?.focus()})}
const paymentTypes=['EFECTIVO','QR','COMBINADO'],providerForm=reactive({nombre:'',nit:'',telefono:'',direccion:''})
const photoUrl=path=>`${proxy.$imgBase}/images/${path}`,money=v=>Number(v||0).toFixed(2),qty=(v,u)=>Number(v||0).toFixed(u==='KG'?3:0),lineTotal=i=>Number(i.cantidad||0)*Number(i.precio_unitario||0),total=computed(()=>items.value.reduce((s,i)=>s+Number(i.total_editable||0),0))
const paymentDifference=computed(()=>Number((total.value-(Number(cashAmount.value)||0)-(Number(qrAmount.value)||0)).toFixed(2)))
watch([paymentType,total],()=>{if(paymentType.value==='EFECTIVO'){cashAmount.value=total.value;qrAmount.value=0}else if(paymentType.value==='QR'){cashAmount.value=0;qrAmount.value=total.value}else if(Number(cashAmount.value)+Number(qrAmount.value)===0){cashAmount.value=total.value;qrAmount.value=0}})
const daysUntil=value=>Math.ceil((new Date(`${value}T12:00:00`)-new Date())/86400000),expiryLabel=value=>daysUntil(value)<0?`Vencido hace ${Math.abs(daysUntil(value))} días`:`Vence en ${daysUntil(value)} días`
// Catálogo paginado de 15 en 15; la búsqueda vuelve a la página 1.
const PER_PAGE=15,page=ref(1),lastPage=ref(1),totalProducts=ref(0),loadingProducts=ref(false)
let searchTimer=null,searchRequest=0,lastScan={code:'',at:0}
function loadProducts(resetPage=false){if(resetPage)page.value=1;const request=++searchRequest;loadingProducts.value=true;return proxy.$axios.get('/productos',{params:{q:search.value||'',per_page:PER_PAGE,page:page.value}}).then(r=>{if(request!==searchRequest)return null;products.value=r.data.data;lastPage.value=r.data.last_page||1;totalProducts.value=r.data.total||0;if(page.value>lastPage.value){page.value=lastPage.value;return loadProducts()}return products.value}).finally(()=>{if(request===searchRequest)loadingProducts.value=false})}
// Mientras se escribe se filtra con un pequeño retardo; si lo escrito es exactamente un código (lector sin Enter) se agrega solo.
function onSearchInput(){clearTimeout(searchTimer);searchTimer=setTimeout(async()=>{const q=String(search.value||'').trim();if(!q)return loadProducts(true);if(isScaleCode(q))return addExact();const list=await loadProducts(true);if(list&&String(search.value||'').trim()===q){const p=list.find(i=>sameCode(i,q));if(p)addScanned(p,1,q.toUpperCase())}},300)}
const sameCode=(p,q)=>{q=String(q).toUpperCase();return String(p.codigo||'').toUpperCase()===q||String(p.codigo_barras||'').toUpperCase()===q}
const ean13=d=>(10-([...d].reduce((t,x,i)=>t+Number(x)*(i%2===0?1:3),0)%10))%10,isScaleCode=q=>/^2\d{12}$/.test(q)&&ean13(q.slice(0,12))===Number(q[12])
// Agrega lo escaneado al carrito (o suma a la línea existente) y deja el buscador listo para el siguiente código.
function addScanned(p,quantity,code){if(code===lastScan.code&&Date.now()-lastScan.at<1000)return;lastScan={code,at:Date.now()};add(p,quantity);proxy.$alert.info(`${p.nombre} agregado`);search.value='';loadProducts(true);searchInput.value?.focus()}
function loadProviders(){proxy.$axios.get('/proveedores').then(r=>providers.value=r.data)}
function add(p,quantity=1){const existing=items.value.find(i=>i.id===p.id);if(existing){existing.cantidad=Number((Number(existing.cantidad||0)+Number(quantity)).toFixed(3));return syncItemTotal(existing)}items.value.push({...p,cantidad:Number(quantity),precio_unitario:Number(p.precio_compra),total_editable:(Number(quantity)*Number(p.precio_compra)).toFixed(2),lote:'',fecha_vencimiento:''})}
// Enter (lector de códigos): etiqueta de balanza 2+código+gramos, o código/código de barras exacto.
async function addExact(){clearTimeout(searchTimer);const q=String(search.value||'').trim().toUpperCase();if(!q)return;try{if(isScaleCode(q)){const code=q.slice(0,7),{data}=await proxy.$axios.get('/productos',{params:{q:code,per_page:PER_PAGE}});const p=data.data.find(i=>sameCode(i,code));if(!p)return proxy.$alert.error(`No existe un producto con código de balanza ${code}`);if(p.unidad!=='KG')return proxy.$alert.error(`${p.nombre} debe tener unidad KG`);return addScanned(p,Number(q.slice(7,12))/1000,q)}let p=products.value.find(i=>sameCode(i,q));if(!p){const {data}=await proxy.$axios.get('/productos',{params:{q,per_page:PER_PAGE}});p=data.data.find(i=>sameCode(i,q))}if(p)return addScanned(p,1,q);if(products.value.length===1)return addScanned(products.value[0],1,q);proxy.$alert.error(`No existe el producto ${q}`)}catch(e){proxy.$alert.error(e.response?.data?.message||'No se pudo buscar el producto')}}
function remove(i){items.value=items.value.filter(x=>x.id!==i.id)}
// Ganancia sobre el costo: (venta - costo) / costo. Solo se avisa si sale del rango; la compra se guarda igual.
const MARGIN_MIN=25,MARGIN_MAX=36,margin=i=>Number(i.precio_unitario)>0?(Number(i.precio_venta||0)-Number(i.precio_unitario))/Number(i.precio_unitario)*100:null
const marginText=i=>margin(i)===null?'':Number(margin(i).toFixed(1)),marginOk=i=>{const m=margin(i);return m!==null&&m>=MARGIN_MIN-0.05&&m<=MARGIN_MAX+0.05}
function applyMargin(item,value){if(value===''||value===null||!(Number(item.precio_unitario)>0))return;item.precio_venta=Number((Number(item.precio_unitario)*(1+Number(value)/100)).toFixed(2))}
function syncItemTotal(item){item.total_editable=(Number(item.cantidad||0)*Number(item.precio_unitario||0)).toFixed(2)}
function applyItemTotal(item){const quantity=Number(item.cantidad||0),value=Math.max(0,Number(item.total_editable)||0);item.total_editable=value.toFixed(2);if(quantity>0)item.precio_unitario=Number((value/quantity).toFixed(4))}
function saveProvider(){if(!providerForm.nombre)return proxy.$alert.error('Ingrese el nombre');providerSaving.value=true;proxy.$axios.post('/proveedores',providerForm).then(r=>{providers.value.push(r.data);provider.value=r.data;providerDialog.value=false;Object.assign(providerForm,{nombre:'',nit:'',telefono:'',direccion:''});proxy.$alert.success('Proveedor guardado')}).catch(e=>proxy.$alert.error(Object.values(e.response?.data?.errors||{})[0]?.[0]||e.response?.data?.message||'No se pudo guardar el proveedor')).finally(()=>providerSaving.value=false)}
function openCheckout(){if(!items.value.length)return proxy.$alert.error('Agrega productos');if(items.value.some(i=>Number(i.cantidad)<=0))return proxy.$alert.error('Revise las cantidades');const out=items.value.filter(i=>!marginOk(i));if(!out.length)return checkoutDialog.value=true;proxy.$alert.confirm(`Ganancia fuera del rango ${MARGIN_MIN}% - ${MARGIN_MAX}% en: ${out.map(i=>`${i.nombre} (${margin(i)===null?'sin costo':margin(i).toFixed(1)+'%'})`).join(', ')}. ¿Continuar de todos modos?`).onOk(()=>checkoutDialog.value=true)}
function save(){if(!provider.value)return proxy.$alert.error('Seleccione un proveedor');if(items.value.some(i=>Number(i.cantidad)<=0))return proxy.$alert.error('Revise las cantidades');if(paymentType.value==='COMBINADO'&&paymentDifference.value!==0)return proxy.$alert.error('Efectivo y QR deben sumar el total');saving.value=true;proxy.$axios.post('/compras',{proveedor_id:provider.value.id,numero_factura:invoice.value,tipo_pago:paymentType.value,monto_efectivo:cashAmount.value,monto_qr:qrAmount.value,comentario:comment.value,detalles:items.value.map(i=>({producto_id:i.id,cantidad:i.cantidad,precio_unitario:i.precio_unitario,precio_venta:Number(i.precio_venta||0),lote:i.lote||null,fecha_vencimiento:i.fecha_vencimiento||null}))}).then(r=>{proxy.$alert.success(`Compra ${r.data.numero} registrada`);printPurchase(r.data);vaciar();checkoutDialog.value=false;loadProducts();searchInput.value?.focus()}).catch(e=>proxy.$alert.error(Object.values(e.response?.data?.errors||{})[0]?.[0]||e.response?.data?.message||'No se pudo guardar')).finally(()=>saving.value=false)}
// Editar el producto desde el carrito: nombre, códigos, categoría, unidad e imagen. Precios y stock no se envían,
// así el backend los conserva; la compra los actualiza al guardarse.
const canEditProduct=computed(()=>proxy.$store.hasPermission('Editar Productos')),editDialog=ref(false),editSaving=ref(false),editPhoto=ref(null),editPreview=ref(''),categorias=ref([]),unitOptions=ref(['GR','KG','ML','LT','UNIDAD'])
const editForm=reactive({id:null,codigo:'',codigo_barras:'',nombre:'',categoria_id:null,unidad:'UNIDAD',foto:null,foto_url:''})
function openEdit(item){Object.assign(editForm,{id:item.id,codigo:item.codigo||'',codigo_barras:item.codigo_barras||'',nombre:item.nombre||'',categoria_id:item.categoria_id||null,unidad:item.unidad||'UNIDAD',foto:item.foto||null,foto_url:''});editPhoto.value=null;previewEdit(null);editDialog.value=true;if(!categorias.value.length)proxy.$axios.get('/productos-catalogos').then(({data})=>{categorias.value=data.categorias;unitOptions.value=[...new Set([...unitOptions.value,...data.unidades])]}).catch(()=>{})}
function previewEdit(file){if(editPreview.value)URL.revokeObjectURL(editPreview.value);editPreview.value=file?URL.createObjectURL(file):''}
function searchEditImage(){window.open(`https://www.google.com/search?tbm=isch&q=${encodeURIComponent(editForm.nombre||'producto')}`,'_blank','noopener')}
// Refleja el producto guardado en todas las compras en espera que lo tengan.
function applyProduct(p){const snap={codigo:p.codigo,codigo_barras:p.codigo_barras,nombre:p.nombre,categoria:p.categoria,categoria_id:p.categoria_id,unidad:p.unidad,foto:p.foto};carritos.value.forEach(c=>c.items.forEach(i=>{if(i.id===p.id)Object.assign(i,snap)}))}
async function saveEdit(){editSaving.value=true;try{const {data}=await proxy.$axios.put(`/productos/${editForm.id}`,{codigo:editForm.codigo,codigo_barras:String(editForm.codigo_barras||'').trim()||null,nombre:editForm.nombre,categoria_id:editForm.categoria_id,unidad:editForm.unidad});applyProduct(data);if(editPhoto.value){const fd=new FormData();fd.append('foto',editPhoto.value);applyProduct((await proxy.$axios.post(`/productos/${data.id}/foto`,fd)).data)}else if(String(editForm.foto_url||'').trim())applyProduct((await proxy.$axios.post(`/productos/${data.id}/foto-url`,{url:editForm.foto_url.trim()})).data);proxy.$alert.success('Producto actualizado');editDialog.value=false;loadProducts()}catch(e){proxy.$alert.error(Object.values(e.response?.data?.errors||{})[0]?.[0]||e.response?.data?.message||'No se pudo guardar el producto')}finally{editSaving.value=false}}
onMounted(()=>{loadProducts(true);loadProviders()})
</script>
<style scoped>
.product-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:5px;max-height:calc(100vh - 160px);overflow:auto}.product-card{overflow:hidden}.product-card:hover{border-color:#c62828;background:#fff8f7}.product-image{height:52px;background:#fff7f6;display:flex;align-items:center;justify-content:center}.product-image img{width:100%;height:100%;object-fit:contain}.product-name{font-size:10px;line-height:12px;height:36px;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden;word-break:break-word}.product-meta{font-size:9px;line-height:12px}.product-tip{font-size:11px;line-height:15px}
.cart-tabs :deep(.q-tab){min-height:34px;padding:0 8px;border-radius:4px 4px 0 0}.cart-tabs :deep(.q-tab__content){font-size:11px;min-width:0}.cart-tab{max-width:170px}
.purchase-card{position:sticky;top:62px}.item-list{max-height:calc(100vh - 250px);overflow:auto}
.cart-head,.cart-row{display:flex;align-items:center;gap:4px;padding:4px 6px}
.cart-head{position:sticky;top:0;z-index:1;background:#fafafa;font-size:10px;text-transform:uppercase;color:#757575}
.cart-row+.cart-row{border-top:1px solid #eee}
.cart-name{flex:1 1 auto;min-width:80px;overflow:hidden;font-size:12px;line-height:15px}.cart-sub{font-size:10px;line-height:13px}
.f-qty{width:64px;flex:0 0 64px}.f-cost{width:74px;flex:0 0 74px}.f-total{width:74px;flex:0 0 74px}.f-sale{width:70px;flex:0 0 70px}.f-pct{width:62px;flex:0 0 62px}.margin-ok :deep(.q-field__control){background:#e8f5e9;color:#2e7d32}.margin-bad :deep(.q-field__control){background:#fff3e0;color:#e65100}.f-exp{width:126px;flex:0 0 126px}.f-lot{width:70px;flex:0 0 70px}.f-del{width:26px;flex:0 0 26px}.f-img{width:30px;flex:0 0 30px}
.cart-row :deep(.q-field__control){height:30px}.cart-row :deep(.q-field__native){font-size:12px;padding:0}.cart-row :deep(.q-field__control-container){padding-top:0}
@media(max-width:1023px){.purchase-card{position:static}.product-grid{max-height:none}.item-list{max-height:none}.cart-head{display:none}.cart-row{flex-wrap:wrap}.cart-name{flex:1 1 100%}}
</style>
