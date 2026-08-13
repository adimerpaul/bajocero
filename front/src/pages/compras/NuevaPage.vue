<template>
  <q-page class="q-pa-sm">
    <div class="row items-center q-mb-sm"><div><div class="text-subtitle1 text-weight-bold">Nueva compra</div><div class="text-caption text-grey-7">Agrega mercadería al inventario</div></div><q-space/><q-btn flat dense icon="groups" label="Proveedores" no-caps to="/proveedores"/><q-btn flat dense icon="shopping_bag" label="Ver compras" no-caps to="/compras"/></div>
    <div class="row q-col-gutter-sm">
      <div class="col-12 col-lg-5">
        <q-card flat bordered>
          <q-card-section class="row q-col-gutter-sm q-pa-sm"><q-input ref="searchInput" v-model="search" dense outlined autofocus clearable debounce="250" class="col" placeholder="Buscar o escanear producto" @update:model-value="loadProducts" @keydown.enter.prevent="addExact"><template #prepend><q-icon name="qr_code_scanner"/></template></q-input></q-card-section>
          <q-separator/><q-card-section class="q-pa-xs product-grid"><q-card v-for="product in products" :key="product.id" flat bordered class="product-card cursor-pointer" @click="add(product)"><div class="product-image"><img v-if="product.foto" :src="photoUrl(product.foto)"/><q-icon v-else name="inventory_2" size="20px" color="grey-4"/></div><div class="q-px-xs q-pb-xs"><div class="product-name ellipsis-2-lines text-weight-bold">{{product.nombre}}</div><div class="row items-center product-meta"><span class="text-grey-8">C {{money(product.precio_compra)}}</span><q-space/><b class="text-primary">V {{money(product.precio_venta)}}</b></div><div class="product-meta text-grey-7 ellipsis">Stock {{qty(product.stock_inicial,product.unidad)}} {{product.unidad}}</div></div><q-tooltip :delay="300" anchor="top middle" self="bottom middle" class="bg-grey-10 product-tip"><div class="text-weight-bold">{{product.nombre}}</div><div>Código: {{product.codigo}}</div><div v-if="product.codigo_barras">Cód. barras: {{product.codigo_barras}}</div><div v-if="product.categoria">Categoría: {{product.categoria}}</div><div>Unidad: {{product.unidad}}</div><div>Stock: {{qty(product.stock_inicial,product.unidad)}} {{product.unidad}}</div><div>Costo: Bs {{money(product.precio_compra)}}</div><div>Venta: Bs {{money(product.precio_venta)}}</div></q-tooltip></q-card></q-card-section>
        </q-card>
      </div>
      <div class="col-12 col-lg-7">
        <q-card flat bordered class="purchase-card"><q-card-section class="row items-center q-py-sm"><q-icon name="add_business" color="primary" size="22px" class="q-mr-xs"/><b>Productos a comprar</b><q-space/><span class="text-caption text-grey-7">{{items.length}} ítem(s)</span></q-card-section><q-separator/>
          <template v-if="items.length">
            <div class="cart-head"><span class="cart-name">Producto</span><span class="f-qty text-center">Cant</span><span class="f-cost text-center">Costo</span><span class="f-total text-center">Total</span><span class="f-exp text-center">Vence</span><span class="f-lot text-center">Lote</span><span class="f-del"/></div>
            <div class="item-list">
              <div v-for="item in items" :key="item.id" class="cart-row">
                <div class="cart-name"><div class="ellipsis text-weight-bold">{{item.nombre}}</div><div class="cart-sub text-grey-7 ellipsis">{{item.codigo}} · {{item.unidad}} · Venta Bs {{money(item.precio_venta)}}<span v-if="item.fecha_vencimiento" :class="daysUntil(item.fecha_vencimiento)<0?'text-negative':'text-orange-9'"> · {{expiryLabel(item.fecha_vencimiento)}}</span></div></div>
                <q-input v-model.number="item.cantidad" dense outlined type="number" min="0.001" step="0.001" class="f-qty" input-class="text-right" @update:model-value="syncItemTotal(item)"/>
                <q-input v-model.number="item.precio_unitario" dense outlined type="number" min="0" step="0.0001" class="f-cost" input-class="text-right" @update:model-value="syncItemTotal(item)"/>
                <q-input v-model.number="item.total_editable" dense outlined type="number" min="0" step="0.01" class="f-total" input-class="text-right" @blur="applyItemTotal(item)"/>
                <q-input v-model="item.fecha_vencimiento" dense outlined type="date" class="f-exp"/>
                <q-input v-model="item.lote" dense outlined class="f-lot"/>
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
  </q-page>
</template>
<script setup>
import { computed, getCurrentInstance, onMounted, reactive, ref, watch } from 'vue'
import { printPurchase } from '../../addons/compraPrint'
import CompanyBanner from '../../components/CompanyBanner.vue'
const {proxy}=getCurrentInstance(),products=ref([]),providers=ref([]),items=ref([]),search=ref(''),provider=ref(null),invoice=ref(''),comment=ref(''),paymentType=ref('EFECTIVO'),cashAmount=ref(0),qrAmount=ref(0),saving=ref(false),providerSaving=ref(false),searchInput=ref(null),providerDialog=ref(false),checkoutDialog=ref(false)
const paymentTypes=['EFECTIVO','QR','COMBINADO'],providerForm=reactive({nombre:'',nit:'',telefono:'',direccion:''})
const photoUrl=path=>`${proxy.$imgBase}/images/${path}`,money=v=>Number(v||0).toFixed(2),qty=(v,u)=>Number(v||0).toFixed(u==='KG'?3:0),lineTotal=i=>Number(i.cantidad||0)*Number(i.precio_unitario||0),total=computed(()=>items.value.reduce((s,i)=>s+Number(i.total_editable||0),0))
const paymentDifference=computed(()=>Number((total.value-(Number(cashAmount.value)||0)-(Number(qrAmount.value)||0)).toFixed(2)))
watch([paymentType,total],()=>{if(paymentType.value==='EFECTIVO'){cashAmount.value=total.value;qrAmount.value=0}else if(paymentType.value==='QR'){cashAmount.value=0;qrAmount.value=total.value}else if(Number(cashAmount.value)+Number(qrAmount.value)===0){cashAmount.value=total.value;qrAmount.value=0}})
const daysUntil=value=>Math.ceil((new Date(`${value}T12:00:00`)-new Date())/86400000),expiryLabel=value=>daysUntil(value)<0?`Vencido hace ${Math.abs(daysUntil(value))} días`:`Vence en ${daysUntil(value)} días`
function loadProducts(){proxy.$axios.get('/productos',{params:{q:search.value,per_page:0}}).then(r=>products.value=r.data.data)}
function loadProviders(){proxy.$axios.get('/proveedores').then(r=>providers.value=r.data)}
function add(p){if(items.value.some(i=>i.id===p.id))return;items.value.push({...p,cantidad:1,precio_unitario:Number(p.precio_compra),total_editable:Number(p.precio_compra).toFixed(2),lote:'',fecha_vencimiento:''})}
async function addExact(){const q=String(search.value||'').trim();const scale=/^2\d{12}$/.test(q)?{code:q.slice(0,7),weight:Number(q.slice(7,12))/1000}:null;if(scale){const {data}=await proxy.$axios.get('/productos',{params:{q:scale.code,per_page:0}});const p=data.data.find(i=>i.codigo===scale.code||i.codigo_barras===scale.code);if(p){add(p);const item=items.value.find(i=>i.id===p.id);if(item&&p.unidad==='KG'){item.cantidad=scale.weight;syncItemTotal(item)}search.value='';loadProducts()}return}const p=products.value.find(i=>i.codigo===q||i.codigo_barras===q);if(p){add(p);search.value='';loadProducts()}}
function remove(i){items.value=items.value.filter(x=>x.id!==i.id)}
function syncItemTotal(item){item.total_editable=(Number(item.cantidad||0)*Number(item.precio_unitario||0)).toFixed(2)}
function applyItemTotal(item){const quantity=Number(item.cantidad||0),value=Math.max(0,Number(item.total_editable)||0);item.total_editable=value.toFixed(2);if(quantity>0)item.precio_unitario=Number((value/quantity).toFixed(4))}
function saveProvider(){if(!providerForm.nombre)return proxy.$alert.error('Ingrese el nombre');providerSaving.value=true;proxy.$axios.post('/proveedores',providerForm).then(r=>{providers.value.push(r.data);provider.value=r.data;providerDialog.value=false;Object.assign(providerForm,{nombre:'',nit:'',telefono:'',direccion:''});proxy.$alert.success('Proveedor guardado')}).catch(e=>proxy.$alert.error(Object.values(e.response?.data?.errors||{})[0]?.[0]||e.response?.data?.message||'No se pudo guardar el proveedor')).finally(()=>providerSaving.value=false)}
function openCheckout(){if(!items.value.length)return proxy.$alert.error('Agrega productos');if(items.value.some(i=>Number(i.cantidad)<=0))return proxy.$alert.error('Revise las cantidades');checkoutDialog.value=true}
function save(){if(!provider.value)return proxy.$alert.error('Seleccione un proveedor');if(items.value.some(i=>Number(i.cantidad)<=0))return proxy.$alert.error('Revise las cantidades');if(paymentType.value==='COMBINADO'&&paymentDifference.value!==0)return proxy.$alert.error('Efectivo y QR deben sumar el total');saving.value=true;proxy.$axios.post('/compras',{proveedor_id:provider.value.id,numero_factura:invoice.value,tipo_pago:paymentType.value,monto_efectivo:cashAmount.value,monto_qr:qrAmount.value,comentario:comment.value,detalles:items.value.map(i=>({producto_id:i.id,cantidad:i.cantidad,precio_unitario:i.precio_unitario,lote:i.lote||null,fecha_vencimiento:i.fecha_vencimiento||null}))}).then(r=>{proxy.$alert.success(`Compra ${r.data.numero} registrada`);printPurchase(r.data);items.value=[];invoice.value='';comment.value='';paymentType.value='EFECTIVO';checkoutDialog.value=false;loadProducts();searchInput.value?.focus()}).catch(e=>proxy.$alert.error(Object.values(e.response?.data?.errors||{})[0]?.[0]||e.response?.data?.message||'No se pudo guardar')).finally(()=>saving.value=false)}
onMounted(()=>{loadProducts();loadProviders()})
</script>
<style scoped>
.product-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:5px;max-height:calc(100vh - 160px);overflow:auto}.product-card{overflow:hidden}.product-card:hover{border-color:#c62828;background:#fff8f7}.product-image{height:52px;background:#fff7f6;display:flex;align-items:center;justify-content:center}.product-image img{width:100%;height:100%;object-fit:contain}.product-name{font-size:11px;line-height:13px;height:26px}.product-meta{font-size:10px;line-height:13px}.product-tip{font-size:11px;line-height:15px}
.purchase-card{position:sticky;top:62px}.item-list{max-height:calc(100vh - 250px);overflow:auto}
.cart-head,.cart-row{display:flex;align-items:center;gap:4px;padding:4px 6px}
.cart-head{position:sticky;top:0;z-index:1;background:#fafafa;font-size:10px;text-transform:uppercase;color:#757575}
.cart-row+.cart-row{border-top:1px solid #eee}
.cart-name{flex:1 1 auto;min-width:80px;overflow:hidden;font-size:12px;line-height:15px}.cart-sub{font-size:10px;line-height:13px}
.f-qty{width:64px;flex:0 0 64px}.f-cost{width:74px;flex:0 0 74px}.f-total{width:74px;flex:0 0 74px}.f-exp{width:126px;flex:0 0 126px}.f-lot{width:70px;flex:0 0 70px}.f-del{width:26px;flex:0 0 26px}
.cart-row :deep(.q-field__control){height:30px}.cart-row :deep(.q-field__native){font-size:12px;padding:0}.cart-row :deep(.q-field__control-container){padding-top:0}
@media(max-width:1023px){.purchase-card{position:static}.product-grid{max-height:none}.item-list{max-height:none}.cart-head{display:none}.cart-row{flex-wrap:wrap}.cart-name{flex:1 1 100%}}
</style>
