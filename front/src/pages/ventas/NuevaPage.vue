<template>
  <q-page class="q-pa-sm">
<!--    <CompanyBanner class="q-mb-sm"/>-->
    <div class="row items-center q-mb-sm">
      <div><div class="text-subtitle1 text-weight-bold">Nueva venta</div><div class="text-caption text-grey-7">Selecciona productos y confirma el carrito</div></div>
      <q-space/><q-btn dense flat icon="receipt_long" label="Ver ventas" no-caps to="/ventas"/>
    </div>
    <div class="row q-col-gutter-sm">
      <div class="col-12 col-md-6">
        <q-card flat bordered>
          <q-card-section class="row q-col-gutter-sm q-pa-sm">
            <q-input ref="searchInput" v-model="search" dense outlined autofocus clearable debounce="250" class="col" placeholder="Buscar nombre, código o escanear etiqueta de balanza · ↑↓ elegir · Enter cantidad" @update:model-value="handleSearchInput" @keydown.enter.prevent="addExact" @keydown.down.prevent="moveSel(1)" @keydown.up.prevent="moveSel(-1)">
              <template #prepend><q-icon name="qr_code_scanner"/></template>
            </q-input>
            <q-select v-model="category" :options="categories" option-label="nombre" dense outlined clearable label="Categoría" style="min-width:170px" @update:model-value="loadProducts(true)"/>
            <q-select v-model="sort" :options="sortOptions" option-label="label" dense outlined label="Ordenar" style="min-width:190px" @update:model-value="loadProducts(true)"><template #prepend><q-icon name="swap_vert"/></template></q-select>
          </q-card-section>
          <q-separator/>
          <q-card-section class="q-pa-sm product-grid">
            <q-card v-for="(product,index) in products" :key="product.id" flat bordered class="product-card cursor-pointer" :class="{'product-card--empty':product.stock_inicial<=0,'product-card--sel':index===selIndex}" :data-index="index" @click="add(product)">
              <div class="product-image"><img v-if="product.foto" :src="photoUrl(product.foto)"/><q-icon v-else name="inventory_2" size="42px" color="grey-4"/></div>
              <q-card-section class="q-pa-xs">
                <div class="text-weight-bold product-name">{{product.nombre}}</div>
                <div class="row items-center no-wrap product-meta"><span class="text-primary text-weight-bold">Bs {{money(product.precio_venta)}}{{product.unidad==='KG'?'/kg':''}}</span><q-space/><q-badge :color="product.stock_inicial>0?'positive':'negative'" :label="`${quantity(product.stock_inicial,product.unidad)} ${product.unidad}`"/></div>
              </q-card-section>
              <q-tooltip :delay="300" anchor="top middle" self="bottom middle" class="bg-grey-10 product-tip"><div class="text-weight-bold">{{product.nombre}}</div><div>Código: {{product.codigo}}</div><div v-if="product.codigo_barras">Cód. barras: {{product.codigo_barras}}</div><div v-if="product.categoria">Categoría: {{product.categoria}}</div><div>Unidad: {{product.unidad}}</div><div>Stock: {{quantity(product.stock_inicial,product.unidad)}} {{product.unidad}}</div><div>Precio: Bs {{money(product.precio_venta)}}{{product.unidad==='KG'?'/kg':''}}</div></q-tooltip>
            </q-card>
            <div v-if="!products.length&&!loadingProducts" class="grid-empty text-center text-grey-6 q-py-lg">Sin productos</div>
          </q-card-section>
          <q-separator/>
          <q-card-section class="row items-center q-py-xs q-px-sm">
            <div class="text-caption text-grey-7">{{totalProducts}} productos · página {{page}} de {{lastPage}}</div>
            <q-space/>
            <q-pagination v-model="page" :max="lastPage" :max-pages="5" boundary-numbers dense size="sm" color="primary" @update:model-value="loadProducts()"/>
          </q-card-section>
        </q-card>
      </div>

      <div class="col-12 col-md-6">
        <q-card flat bordered class="cart-card">
          <q-tabs v-model="tabCarrito" dense no-caps align="left" class="cart-tabs bg-grey-2 text-grey-8" active-color="primary" active-bg-color="white" indicator-color="primary" narrow-indicator :breakpoint="0">
            <q-tab v-for="c in carritos" :key="c.id" :name="c.id" class="cart-tab" @dblclick="renombrarCarrito(c)">
              <div class="row items-center no-wrap">
                <q-icon name="shopping_cart" size="15px" class="q-mr-xs"/><span class="ellipsis">{{c.nombre}}</span>
                <q-badge v-if="c.items.length" color="primary" class="q-ml-xs" :label="c.items.length"/>
              </div>
              <q-tooltip :delay="400" anchor="top middle" self="bottom middle" class="bg-grey-10">{{c.items.length}} producto(s) · Bs {{money(cartTotal(c))}} · doble clic para renombrar</q-tooltip>
            </q-tab>
          </q-tabs>
          <q-separator/>
          <q-card-section class="row items-center q-py-sm"><q-icon name="shopping_cart" color="primary" size="22px" class="q-mr-xs"/><div class="text-subtitle1 text-weight-bold ellipsis">{{carrito.nombre}}</div><q-space/><q-badge color="primary" :label="itemCount"/><q-btn dense flat no-caps size="sm" class="q-ml-sm" icon="delete_sweep" color="negative" label="Limpiar" :disable="!cart.length" @click="clearCart"/></q-card-section>
          <q-separator/>
          <q-list v-if="cart.length" separator class="cart-list">
            <q-item v-for="item in cart" :key="item.id" dense class="q-px-sm">
              <q-item-section avatar class="cart-avatar"><q-avatar rounded size="28px" color="grey-2"><img v-if="item.foto" :src="photoUrl(item.foto)"/><q-icon v-else name="inventory_2" size="16px"/></q-avatar></q-item-section>
              <q-item-section>
                <q-item-label lines="1" class="text-caption text-weight-medium">{{item.nombre}}</q-item-label>
                <div class="row items-end no-wrap cart-fields">
                  <label class="field-label">Cant. ({{item.unidad}})<input v-model.number="item.cantidad" class="qty-input" type="number" :min="minimumQty(item)" :max="hasStock(item)?item.stock_inicial:undefined" :step="quantityStep(item)" @blur="validateQty(item)"></label>
                  <label class="field-label">Precio{{item.unidad==='KG'?'/kg':''}}
                    <span class="price-field">
                      <input v-model.number="item.precio_venta" class="price-input" type="number" min="0" step="0.0001" :list="`price-list-${item.id}`" @input="syncLineTotal(item)">
                      <q-btn dense flat round size="xs" icon="arrow_drop_down" color="primary" aria-label="Ver precios">
                        <q-menu anchor="bottom right" self="top right">
                          <q-list dense style="min-width:150px">
                            <q-item v-for="option in priceOptions(item)" :key="option.level" v-close-popup clickable @click="selectPrice(item,option.value)">
                              <q-item-section>Precio {{option.level}}</q-item-section>
                              <q-item-section side>Bs {{money(option.value)}}</q-item-section>
                            </q-item>
                          </q-list>
                        </q-menu>
                      </q-btn>
                    </span>
                    <datalist :id="`price-list-${item.id}`">
                      <option v-for="option in priceOptions(item)" :key="option.level" :value="option.value" :label="`Precio ${option.level} · Bs ${money(option.value)}`"/>
                    </datalist>
                  </label>
                  <label class="field-label total-label">Total<input v-model.number="item.total_editable" class="total-input" type="number" min="0" step="0.01" @keyup.enter="$event.target.blur()" @blur="applyLineTotal(item)"></label>
                  <div class="row items-center no-wrap q-ml-auto"><q-btn dense flat round size="sm" icon="remove" @click="changeQty(item,-quantityStep(item))"/><q-btn dense flat round size="sm" icon="add" @click="changeQty(item,quantityStep(item))"/><q-btn dense flat round size="sm" icon="delete" color="negative" @click="removeItem(item)"/></div>
                </div>
              </q-item-section>
            </q-item>
          </q-list>
          <q-card-section v-else class="text-center text-grey-6 q-py-xl"><q-icon name="remove_shopping_cart" size="42px"/><div>Agrega productos</div></q-card-section>
          <q-separator/>
          <q-card-section class="q-pa-sm">
            <q-input v-model.number="discount" dense outlined type="number" min="0" :max="subtotal" step="0.01" label="Descuento" prefix="Bs" class="q-mb-sm"/>
            <q-select v-model="paymentType" dense outlined :options="paymentTypes" label="Tipo de pago" class="q-mb-sm"/>
            <div v-if="paymentType==='COMBINADO'" class="row q-col-gutter-sm q-mb-sm">
              <q-input v-model.number="cashAmount" dense outlined type="number" min="0" step="0.01" label="Monto efectivo" prefix="Bs" class="col-6"/>
              <q-input v-model.number="qrAmount" dense outlined type="number" min="0" step="0.01" label="Monto QR" prefix="Bs" class="col-6"/>
              <div class="col-12 text-caption" :class="paymentDifference===0?'text-positive':'text-negative'">Diferencia: Bs {{money(paymentDifference)}}</div>
            </div>
            <q-banner v-else dense rounded :class="paymentType==='EFECTIVO'?'bg-green-1 text-green-9':'bg-blue-1 text-blue-9'" class="q-mb-sm"><q-icon :name="paymentType==='EFECTIVO'?'payments':'qr_code_2'" class="q-mr-xs"/>Pago {{paymentType}}: Bs {{money(total)}}</q-banner>
            <q-input v-model="observation" dense outlined autogrow label="Observación" class="q-mb-sm"/>
            <div class="row text-body2"><span>Subtotal</span><q-space/><b>Bs {{money(subtotal)}}</b></div>
            <div class="row text-body2 text-negative"><span>Descuento</span><q-space/><b>- Bs {{money(validDiscount)}}</b></div>
            <div class="row text-h6 text-primary q-mt-xs"><b>Total</b><q-space/><b>Bs {{money(total)}}</b></div>
          </q-card-section>
          <q-card-actions class="q-pa-sm"><q-btn class="full-width" color="positive" unelevated icon="point_of_sale" label="Confirmar venta" no-caps :disable="!cart.length" :loading="saving" @click="confirmSale"/></q-card-actions>
        </q-card>
      </div>
    </div>
    <q-dialog v-model="qtyDialog" @hide="searchInput?.focus()">
      <q-card style="width:360px;max-width:94vw"><q-form @submit.prevent="confirmQty">
        <q-card-section class="row no-wrap items-center q-pb-sm">
          <q-avatar rounded size="48px" color="grey-2"><img v-if="qtyProduct?.foto" :src="photoUrl(qtyProduct.foto)"/><q-icon v-else name="inventory_2" color="grey-5"/></q-avatar>
          <div class="q-ml-sm"><div class="text-weight-bold">{{qtyProduct?.nombre}}</div><div class="text-caption text-grey-7">Bs {{money(qtyProduct?.precio_venta)}}{{isWeighted(qtyProduct)?'/kg':''}} · stock {{quantity(qtyProduct?.stock_inicial,qtyProduct?.unidad)}} {{qtyProduct?.unidad}}</div></div>
        </q-card-section>
        <q-card-section class="q-pt-none">
          <q-input ref="qtyInput" v-model.number="qtyValue" dense outlined autofocus type="number" :step="quantityStep(qtyProduct)" min="0" :label="isWeighted(qtyProduct)?'Peso (kg)':'Cantidad'" :suffix="qtyProduct?.unidad" input-class="text-h6 text-right" @focus="$event.target.select()"/>
          <div class="row q-mt-sm text-subtitle1"><span>Total</span><q-space/><b class="text-primary">Bs {{money(Number(qtyProduct?.precio_venta||0)*(Number(qtyValue)||0))}}</b></div>
          <div class="text-caption text-grey-6">Enter para agregar · Esc para cancelar</div>
        </q-card-section>
        <q-card-actions align="right" class="q-pa-sm"><q-btn flat no-caps label="Cancelar" v-close-popup/><q-btn unelevated no-caps type="submit" color="primary" icon="add_shopping_cart" label="Agregar"/></q-card-actions>
      </q-form></q-card>
    </q-dialog>
    <q-dialog v-model="docDialog" @hide="searchInput?.focus()">
      <q-card style="width:820px;max-width:96vw"><q-form @submit.prevent="emitir">
        <q-card-section class="row items-center q-pb-sm"><div><div class="text-subtitle1 text-weight-bold">Datos del cliente</div><div class="text-caption text-grey-7">Carnet o NIT del cliente</div></div><q-space/><div class="text-h6 text-primary text-weight-bold">Bs {{money(total)}}</div></q-card-section>
        <q-card-section class="q-pt-none row q-col-gutter-md">
          <div class="col-12 col-md-6">
            <q-markup-table dense flat bordered separator="horizontal" class="resumen-items">
              <thead><tr><th class="text-left">Producto</th><th class="text-right">Cant.</th><th class="text-right">Precio</th><th class="text-right">Total</th></tr></thead>
              <tbody><tr v-for="item in cart" :key="item.id"><td class="text-left ellipsis" style="max-width:170px">{{item.nombre}}</td><td class="text-right">{{quantity(item.cantidad,item.unidad)}} {{item.unidad}}</td><td class="text-right">{{money(item.precio_venta)}}</td><td class="text-right text-weight-bold">{{money(Number(item.precio_venta)*Number(item.cantidad))}}</td></tr></tbody>
            </q-markup-table>
            <div class="q-mt-sm text-body2"><div class="row"><span>Subtotal</span><q-space/>Bs {{money(subtotal)}}</div><div class="row text-negative"><span>Descuento</span><q-space/>- Bs {{money(validDiscount)}}</div><div class="row text-h6 text-primary"><b>Total</b><q-space/><b>Bs {{money(total)}}</b></div></div>
          </div>
          <div class="col-12 col-md-6">
            <ClienteFactura v-model:cliente="cliente" dialogo/>
            <div class="row q-col-gutter-xs">
              <q-input v-model.number="discount" dense outlined type="number" min="0" :max="subtotal" step="0.01" label="Descuento" prefix="Bs" class="col-5"/>
              <q-select v-model="paymentType" dense outlined :options="paymentTypes" label="Tipo de pago" class="col-7"/>
              <template v-if="paymentType==='COMBINADO'">
                <q-input v-model.number="cashAmount" dense outlined type="number" min="0" step="0.01" label="Efectivo" prefix="Bs" class="col-6"/>
                <q-input v-model.number="qrAmount" dense outlined type="number" min="0" step="0.01" label="QR" prefix="Bs" class="col-6"/>
                <div class="col-12 text-caption" :class="paymentDifference===0?'text-positive':'text-negative'">Diferencia: Bs {{money(paymentDifference)}}</div>
              </template>
              <q-input v-model="observation" dense outlined autogrow label="Observación" class="col-12"/>
            </div>
          </div>
        </q-card-section>
        <q-card-actions align="right" class="q-pa-sm"><q-btn flat no-caps label="Cancelar" v-close-popup/><q-btn unelevated no-caps type="submit" color="positive" icon="point_of_sale" label="Emitir" :loading="saving"/></q-card-actions>
      </q-form></q-card>
    </q-dialog>
  </q-page>
</template>

<script setup>
import { computed, getCurrentInstance, nextTick, onMounted, ref, watch } from 'vue'
import { printSale } from '../../addons/ventaPrint'
import { armarVentaOffline, guardarVentaOffline, nuevoUuid } from '../../addons/ventasOffline'
import CompanyBanner from '../../components/CompanyBanner.vue'
import ClienteFactura from '../../components/ClienteFactura.vue'
import { useCarritos } from '../../stores/carritos-store'
const {proxy}=getCurrentInstance()
const puedeOffline=computed(()=>proxy.$store.hasPermission('Crear Ventas Offline'))
// Hasta 5 carritos en espera: cada uno guarda sus ítems y su cabecera en el store (y en localStorage).
const {lista:carritos,activoId,carrito,activar,vaciar,renombrar,campo}=useCarritos('ventas')
const cart=campo('items'),discount=campo('descuento'),observation=campo('observacion'),paymentType=campo('tipoPago'),cashAmount=campo('efectivo'),qrAmount=campo('qr'),cliente=campo('cliente')
// Con carnet o NIT se factura; sin documento la venta se registra sin factura. La pantalla sólo dice "Emitir".
const docDialog=ref(false),tipoComprobante=computed(()=>['','0'].includes(String(cliente.value?.numero_documento??'').trim())?'RECIBO':'FACTURA')
const products=ref([]),categories=ref([]),search=ref(''),category=ref(null),saving=ref(false),searchInput=ref(null)
// Venta rápida con teclado: ↑↓ recorren los resultados, Enter pide la cantidad o el peso, Enter lo agrega.
const selIndex=ref(-1),qtyDialog=ref(false),qtyProduct=ref(null),qtyValue=ref(null),qtyInput=ref(null)
const sortOptions=[{label:'Stock (mayor a menor)',by:'stock_inicial',dir:'desc'},{label:'Stock (menor a mayor)',by:'stock_inicial',dir:'asc'},{label:'Nombre (A-Z)',by:'nombre',dir:'asc'},{label:'Precio (mayor a menor)',by:'precio_venta',dir:'desc'},{label:'Precio (menor a mayor)',by:'precio_venta',dir:'asc'}]
const sort=ref(sortOptions[0])
const PER_PAGE=15,page=ref(1),lastPage=ref(1),totalProducts=ref(0),loadingProducts=ref(false)
const paymentTypes=['EFECTIVO','QR','COMBINADO']
let processingBarcode=false
let searchRequest=0
const photoUrl=path=>`${proxy.$imgBase}/images/${path}`,money=v=>Number(v||0).toFixed(2)
const isWeighted=item=>item?.unidad==='KG',quantityStep=item=>isWeighted(item)?0.001:1,minimumQty=item=>quantityStep(item)
const hasStock=item=>Number(item?.stock_inicial)>0
const quantity=(value,unit)=>Number(value||0).toFixed(unit==='KG'?3:0)
const priceOptions=item=>[1,2,3,4,5].map(level=>({level,value:Number(item?.[`precio_${level}`]??(level===5?item?.precio_venta:0))}))
function selectPrice(item,value){item.precio_venta=Number(value);syncLineTotal(item)}
const subtotal=computed(()=>cart.value.reduce((sum,i)=>sum+Number(i.precio_venta)*i.cantidad,0))
const validDiscount=computed(()=>Math.min(Math.max(Number(discount.value)||0,0),subtotal.value))
const total=computed(()=>subtotal.value-validDiscount.value)
const itemCount=computed(()=>cart.value.length)
const cartTotal=c=>{const sub=(c?.items||[]).reduce((sum,i)=>sum+Number(i.precio_venta||0)*Number(i.cantidad||0),0);return sub-Math.min(Math.max(Number(c?.descuento)||0,0),sub)}
const tabCarrito=computed({get:()=>activoId.value,set:id=>{activar(id);searchInput.value?.focus()}})
function renombrarCarrito(c){proxy.$alert.dialogPrompt(`Nuevo nombre para ${c.nombre}`).onOk(value=>renombrar(c.id,value))}
const paymentDifference=computed(()=>Number((total.value-(Number(cashAmount.value)||0)-(Number(qrAmount.value)||0)).toFixed(2)))
watch([paymentType,total],()=>{if(paymentType.value==='EFECTIVO'){cashAmount.value=total.value;qrAmount.value=0}else if(paymentType.value==='QR'){cashAmount.value=0;qrAmount.value=total.value}else if(Number(cashAmount.value)+Number(qrAmount.value)===0){cashAmount.value=total.value;qrAmount.value=0}})
function loadProducts(resetPage=false){if(resetPage)page.value=1;loadingProducts.value=true;return proxy.$axios.get('/productos',{params:{q:search.value,categoria_id:category.value?.id,sort_by:sort.value?.by||'stock_inicial',sort_dir:sort.value?.dir||'desc',per_page:PER_PAGE,page:page.value}}).then(r=>{products.value=r.data.data;lastPage.value=r.data.last_page||1;totalProducts.value=r.data.total||0;if(page.value>lastPage.value){page.value=lastPage.value;return loadProducts()}return products.value}).finally(()=>{loadingProducts.value=false})}
async function handleSearchInput(value){if(parseScaleBarcode(value))return addExact();const request=++searchRequest;const list=await loadProducts(true);if(request!==searchRequest)return;const code=String(value||'').trim().toUpperCase();selIndex.value=code&&list.length?0:-1;if(!code)return;const product=list.find(p=>String(p.codigo_barras||'').trim().toUpperCase()===code);if(product){add(product,isWeighted(product)?null:1);search.value='';loadProducts(true);searchInput.value?.focus()}}
function add(product,amount=null){if(!product?.id)return proxy.$alert.error('El producto no existe');const requested=Number(amount??quantityStep(product));const item=cart.value.find(i=>i.id===product.id);const next=Number(((item?.cantidad||0)+requested).toFixed(3));if(hasStock(product)&&next>Number(product.stock_inicial)+.0001)return proxy.$alert.error(`Stock insuficiente: disponible ${quantity(product.stock_inicial,product.unidad)} ${product.unidad}`);if(item){item.cantidad=next;syncLineTotal(item)}else cart.value.push({...product,cantidad:requested,total_editable:(Number(product.precio_venta)*requested).toFixed(2)});proxy.$alert.success(`${product.nombre} se agregó al carrito`,`${quantity(next,product.unidad)} ${product.unidad} · Bs ${money(Number(product.precio_venta)*next)}`)}
function parseScaleBarcode(value){const code=String(value||'').trim();if(!/^2\d{12}$/.test(code))return null;const expected=ean13CheckDigit(code.slice(0,12));if(expected!==Number(code[12]))return null;return{productCode:code.slice(0,7),weight:Number(code.slice(7,12))/1000}}
function ean13CheckDigit(firstTwelve){const sum=[...firstTwelve].reduce((total,digit,index)=>total+Number(digit)*(index%2===0?1:3),0);return(10-(sum%10))%10}
async function addExact(){const q=(search.value||'').trim().toUpperCase();const scale=parseScaleBarcode(q);if(scale){if(processingBarcode)return;processingBarcode=true;try{const {data}=await proxy.$axios.get('/productos',{params:{q:scale.productCode,per_page:0}});const product=data.data.find(p=>String(p.codigo)===scale.productCode||String(p.codigo_barras)===scale.productCode);if(!product)return proxy.$alert.error(`No existe un producto con código de balanza ${scale.productCode}`);if(product.unidad!=='KG')return proxy.$alert.error(`${product.nombre} debe tener unidad KG`);add(product,scale.weight);search.value='';loadProducts(true);return}catch(e){return proxy.$alert.error(e.response?.data?.message||'No se pudo leer la etiqueta de balanza')}finally{processingBarcode=false}}if(!q||processingBarcode)return;const matches=p=>String(p.codigo||'').toUpperCase()===q||String(p.codigo_barras||'').toUpperCase()===q;let product=products.value.find(matches);if(!product){processingBarcode=true;try{const {data}=await proxy.$axios.get('/productos',{params:{q,per_page:PER_PAGE}});product=data.data.find(matches)}catch(e){return proxy.$alert.error(e.response?.data?.message||'No se pudo buscar el producto')}finally{processingBarcode=false}}if(!product&&products.value[selIndex.value])return openQty(products.value[selIndex.value]);if(!product)return proxy.$alert.error(`No existe el producto ${q}`);add(product,isWeighted(product)?null:1);search.value='';loadProducts(true)}
function moveSel(step){if(!products.value.length)return;selIndex.value=Math.min(Math.max((selIndex.value<0?(step>0?-1:products.value.length):selIndex.value)+step,0),products.value.length-1);nextTick(()=>document.querySelector(`.product-card[data-index="${selIndex.value}"]`)?.scrollIntoView({block:'nearest'}))}
function openQty(product){qtyProduct.value=product;qtyValue.value=isWeighted(product)?null:1;qtyDialog.value=true}
function confirmQty(){const product=qtyProduct.value,amount=isWeighted(product)?Math.round(Number(qtyValue.value)*1000)/1000:Math.floor(Number(qtyValue.value));if(!(amount>0))return proxy.$alert.error(isWeighted(product)?'Ingresa el peso en kg':'Ingresa la cantidad');qtyDialog.value=false;add(product,amount);search.value='';selIndex.value=-1;loadProducts(true)}
function changeQty(item,amount){const next=Number((Number(item.cantidad)+amount).toFixed(3));if(next<minimumQty(item))return removeItem(item);if(hasStock(item)&&next>Number(item.stock_inicial)+.0001)return proxy.$alert.error('Stock insuficiente');item.cantidad=next;syncLineTotal(item)}
function validateQty(item){let value=Number(item.cantidad)||minimumQty(item);value=isWeighted(item)?Math.round(value*1000)/1000:Math.floor(value);if(hasStock(item)&&value>Number(item.stock_inicial)){item.cantidad=Number(item.stock_inicial);proxy.$alert.error('La cantidad fue ajustada al stock disponible')}else item.cantidad=Math.max(minimumQty(item),value);syncLineTotal(item)}
function syncLineTotal(item){item.total_editable=(Number(item.precio_venta||0)*Number(item.cantidad||0)).toFixed(2)}
function applyLineTotal(item){const totalValue=Math.max(0,Number(item.total_editable)||0),lineQuantity=Math.max(minimumQty(item),Number(item.cantidad)||minimumQty(item));item.total_editable=totalValue.toFixed(2);item.precio_venta=Number((totalValue/lineQuantity).toFixed(4))}
function removeItem(item){cart.value=cart.value.filter(i=>i.id!==item.id)}
function clearCart(){if(!cart.value.length)return;proxy.$alert.confirm(`¿Vaciar ${carrito.value.nombre}?`).onOk(()=>{vaciar();proxy.$alert.info('Carrito vacío');searchInput.value?.focus()})}
function confirmSale(){cart.value.forEach(item=>{const requestedTotal=item.total_editable;validateQty(item);item.total_editable=requestedTotal;applyLineTotal(item)});if(cart.value.some(i=>Number(i.precio_venta)<0||i.precio_venta===''))return proxy.$alert.error('Revisa los precios de venta');if(paymentType.value==='COMBINADO'&&paymentDifference.value!==0)return proxy.$alert.error('Efectivo y QR deben sumar el total');docDialog.value=true}
async function emitir(){if(paymentType.value==='COMBINADO'&&paymentDifference.value!==0)return proxy.$alert.error('Efectivo y QR deben sumar el total');if(tipoComprobante.value==='FACTURA'&&!String(cliente.value.cliente_nombre||'').trim())return proxy.$alert.error('Ingresa el nombre o razón social del cliente');if(saving.value)return;docDialog.value=false;saving.value=true;const uuid=nuevoUuid();try{const {data}=await proxy.$axios.post('/ventas',{uuid,descuento:validDiscount.value,tipo_pago:paymentType.value,monto_efectivo:cashAmount.value,monto_qr:qrAmount.value,observacion:observation.value,tipo_comprobante:tipoComprobante.value,...(tipoComprobante.value==='FACTURA'?cliente.value:{}),detalles:cart.value.map(i=>({producto_id:i.id,cantidad:i.cantidad,precio_venta:i.precio_venta}))});avisarFactura(data);printSale(data);vaciar();loadProducts();searchInput.value?.focus()}catch(e){if(!e.response&&puedeOffline.value)return guardarOffline(uuid);proxy.$alert.error(Object.values(e.response?.data?.errors||{})[0]?.[0]||e.response?.data?.message||'No se pudo registrar la venta')}finally{saving.value=false}}
// La venta ya quedó registrada: esto sólo informa qué pasó con la factura en Impuestos.
function avisarFactura(data){if(data.tipo_comprobante!=='FACTURA')return proxy.$alert.success(`Venta ${data.numero} registrada`);if(data.estado_siat==='VALIDADA')return proxy.$alert.success(`Factura N° ${data.numero_factura} emitida`,`Venta ${data.numero} · validada por Impuestos`);if(data.estado_siat==='PENDIENTE_EVENTO')return proxy.$alert.warning(`Factura N° ${data.numero_factura} emitida sin conexión`,'Se enviará a Impuestos con un evento significativo');proxy.$alert.error(`Venta ${data.numero} registrada, pero la factura no fue aceptada`,`${data.siat_mensaje||'Sin detalle'} · Corrígela desde Ventas → Reemitir factura`)}
// El servidor no respondió: en vez de perder el cobro se guarda en la cola offline.
function guardarOffline(uuid){proxy.$alert.dialog('Sin conexión con el servidor','¿Guardar esta venta como venta offline para enviarla después?').onOk(()=>{const venta=armarVentaOffline({uuid,comprobante:tipoComprobante.value,cliente:cliente.value,items:cart.value,descuento:validDiscount.value,tipoPago:paymentType.value,efectivo:cashAmount.value,qr:qrAmount.value,observacion:observation.value,usuario:proxy.$store.user.name||proxy.$store.user.username||''});try{guardarVentaOffline(venta)}catch(err){return proxy.$alert.error(err.message)}proxy.$alert.success(`Venta ${venta.numero_local} guardada sin conexión`,'Envíala desde Ventas offline cuando vuelva el internet');printSale({...venta,numero:venta.numero_local,estado:'PENDIENTE',pendiente:true});vaciar();searchInput.value?.focus()})}
onMounted(()=>{loadProducts();proxy.$axios.get('/productos-catalogos').then(r=>categories.value=r.data.categorias)})
</script>

<style scoped>
.resumen-items{max-height:42vh;overflow:auto}.resumen-items td,.resumen-items th{font-size:11px;padding:3px 6px}
.product-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(105px,1fr));gap:5px;max-height:calc(100vh - 210px);overflow:auto}.grid-empty{grid-column:1/-1}.product-card{transition:.15s;overflow:hidden}.product-card:hover{border-color:#c62828;transform:translateY(-1px)}.product-card--empty{opacity:.55}.product-card--sel{border-color:#c62828;box-shadow:0 0 0 2px #c62828 inset;background:#fff5f5}.product-image{height:48px;background:#fff7f6;display:flex;align-items:center;justify-content:center}.product-image img{width:100%;height:100%;object-fit:contain}.product-name{font-size:10px;line-height:12px;height:36px;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden;word-break:break-word}.product-meta{font-size:10px}.product-tip{font-size:11px;line-height:15px}.product-meta .q-badge{font-size:10px;padding:1px 4px}.cart-tabs :deep(.q-tab){min-height:34px;padding:0 8px;border-radius:4px 4px 0 0}.cart-tabs :deep(.q-tab__content){font-size:11px;min-width:0}.cart-tab{max-width:170px}.cart-card{position:sticky;top:62px}.cart-list{max-height:38vh;overflow:auto}.cart-avatar{min-width:28px;padding-right:6px}.cart-fields{gap:6px;margin-top:2px}.field-label{font-size:9px;color:#607d8b;display:flex;flex-direction:column;line-height:11px}.price-field{display:flex;align-items:center}.price-input,.qty-input,.total-input{width:66px;height:22px;border:1px solid #cfd8dc;border-radius:4px;padding:1px 4px;font-size:12px;color:#263238;background:#fff}.qty-input{width:58px}.total-input{width:70px;font-weight:700;color:#b71c1c}.price-input:focus,.qty-input:focus,.total-input:focus{outline:1px solid #c62828;border-color:#c62828}@media(max-width:1023px){.cart-card{position:static}.product-grid{max-height:none}}
</style>
