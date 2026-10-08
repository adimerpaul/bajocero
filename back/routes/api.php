<?php

use App\Http\Controllers\AlmacenController;
use App\Http\Controllers\BajaController;
use App\Http\Controllers\ClienteController;
use App\Http\Controllers\CompraController;
use App\Http\Controllers\ConfiguracionController;
use App\Http\Controllers\ImagenController;
use App\Http\Controllers\ProductoController;
use App\Http\Controllers\SiatController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\VentaController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [UserController::class, 'login']);
Route::get('/configuracion', [ConfiguracionController::class, 'show']);
// Fotos (avatar, logo, productos) servidas por el API para que el frontend pueda
// guardarlas en base64 y mostrarlas sin conexión.
Route::get('/imagen/{archivo}', [ImagenController::class, 'show'])->where('archivo', '[A-Za-z0-9._/-]+');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [UserController::class, 'me']);
    Route::post('/logout', [UserController::class, 'logout']);
    Route::put('/cambiar-password', [UserController::class, 'changePassword']);

    Route::get('/users', [UserController::class, 'index']);
    Route::post('/users', [UserController::class, 'store']);
    Route::put('/users/{id}', [UserController::class, 'update']);
    Route::delete('/users/{id}', [UserController::class, 'destroy']);
    Route::put('/users/{id}/reset-password', [UserController::class, 'resetPassword']);
    Route::post('/users/{id}/avatar', [UserController::class, 'uploadAvatar']);
    Route::get('/permissions', [UserController::class, 'permissions']);
    Route::get('/users/{id}/permissions', [UserController::class, 'userPermissions']);
    Route::put('/users/{id}/permissions', [UserController::class, 'updateUserPermissions']);

    Route::get('/productos', [ProductoController::class, 'index']);
    Route::get('/productos-catalogos', [ProductoController::class, 'catalogos']);
    Route::get('/productos-exportar/excel', [ProductoController::class, 'exportExcel']);
    Route::get('/productos-exportar/pdf', [ProductoController::class, 'exportPdf']);
    Route::get('/productos-plantilla-stock', [ProductoController::class, 'plantillaStock']);
    Route::post('/productos-importar-stock', [ProductoController::class, 'importarStock']);
    Route::post('/categorias', [ProductoController::class, 'storeCategoria']);
    Route::put('/categorias/{categoria}', [ProductoController::class, 'updateCategoria']);
    Route::delete('/categorias/{categoria}', [ProductoController::class, 'destroyCategoria']);
    Route::get('/productos/{producto}/movimientos', [ProductoController::class, 'movimientos']);
    Route::get('/productos/{producto}/auditoria', [ProductoController::class, 'auditoria']);
    Route::patch('/productos/{producto}/codigo-barras', [ProductoController::class, 'updateBarcode']);
    Route::post('/productos', [ProductoController::class, 'store']);
    Route::put('/productos/{producto}', [ProductoController::class, 'update']);
    Route::post('/productos/{producto}/foto', [ProductoController::class, 'uploadPhoto']);
    Route::post('/productos/{producto}/foto-url', [ProductoController::class, 'uploadPhotoFromUrl']);
    Route::delete('/productos/{producto}', [ProductoController::class, 'destroy']);

    Route::get('/ventas', [VentaController::class, 'index']);
    Route::post('/ventas', [VentaController::class, 'store']);
    Route::post('/ventas-offline/verificar', [VentaController::class, 'verificarOffline']);
    Route::get('/ventas-resumen', [VentaController::class, 'summary']);
    Route::get('/dashboard', [VentaController::class, 'dashboard']);
    Route::get('/ventas-exportar/excel', [VentaController::class, 'exportExcel']);
    Route::get('/ventas-exportar/pdf', [VentaController::class, 'exportPdf']);
    Route::get('/ventas-exportar/precios-modificados/excel', [VentaController::class, 'exportChangedPricesExcel']);
    Route::get('/ventas-exportar/precios-modificados/pdf', [VentaController::class, 'exportChangedPricesPdf']);
    Route::get('/ventas-motivos-anulacion', [VentaController::class, 'motivosAnulacion']);
    Route::get('/ventas-motivos-evento', [VentaController::class, 'motivosEvento']);
    Route::post('/ventas-enviar-pendientes', [VentaController::class, 'enviarPendientes']);
    Route::get('/ventas/{venta}', [VentaController::class, 'show']);
    Route::put('/ventas/{venta}/anular', [VentaController::class, 'cancel']);
    Route::put('/ventas/{venta}/revertir-anulacion', [VentaController::class, 'revertirAnulacion']);
    Route::get('/ventas/{venta}/verificar-factura', [VentaController::class, 'verificarFactura']);
    Route::put('/ventas/{venta}/reemitir-factura', [VentaController::class, 'reemitirFactura']);
    Route::post('/ventas/{venta}/enviar-evento', [VentaController::class, 'enviarEvento']);
    Route::get('/ventas/{venta}/factura-pdf', [VentaController::class, 'facturaPdf']);
    Route::post('/ventas/{venta}/enviar-factura', [VentaController::class, 'enviarFactura']);

    Route::get('/clientes', [ClienteController::class, 'index']);
    Route::get('/clientes-buscar', [ClienteController::class, 'buscar']);
    Route::get('/clientes-verificar-nit', [ClienteController::class, 'verificarNit']);
    Route::post('/clientes', [ClienteController::class, 'store']);
    Route::put('/clientes/{cliente}', [ClienteController::class, 'update']);
    Route::delete('/clientes/{cliente}', [ClienteController::class, 'destroy']);

    Route::get('/compras', [CompraController::class, 'index']);
    Route::get('/compras-resumen', [CompraController::class, 'summary']);
    Route::post('/compras', [CompraController::class, 'store']);
    Route::get('/compras/{compra}', [CompraController::class, 'show']);
    Route::put('/compras/{compra}/anular', [CompraController::class, 'cancel']);
    Route::get('/proveedores', [CompraController::class, 'proveedores']);
    Route::post('/proveedores', [CompraController::class, 'storeProveedor']);
    Route::get('/vencimientos', [CompraController::class, 'vencimientos']);

    Route::get('/bajas', [BajaController::class, 'index']);
    Route::get('/bajas-resumen', [BajaController::class, 'summary']);
    Route::get('/bajas-catalogos', [BajaController::class, 'catalogos']);
    Route::get('/bajas-lotes', [BajaController::class, 'lotes']);
    Route::post('/bajas', [BajaController::class, 'store']);
    Route::get('/bajas/{baja}', [BajaController::class, 'show']);
    Route::put('/bajas/{baja}/anular', [BajaController::class, 'cancel']);

    Route::get('/almacenes', [AlmacenController::class, 'index']);
    Route::get('/almacenes-resumen', [AlmacenController::class, 'summary']);
    Route::post('/almacenes', [AlmacenController::class, 'store']);
    Route::get('/almacenes/{almacen}', [AlmacenController::class, 'show']);
    Route::put('/almacenes/{almacen}', [AlmacenController::class, 'update']);
    Route::get('/almacenes/{almacen}/avance', [AlmacenController::class, 'progress']);
    Route::get('/almacenes/{almacen}/detalles', [AlmacenController::class, 'detalles']);
    Route::post('/almacenes/{almacen}/detalles', [AlmacenController::class, 'storeDetalle']);
    Route::put('/almacenes/{almacen}/detalles/{detalle}', [AlmacenController::class, 'updateDetalle']);
    Route::delete('/almacenes/{almacen}/detalles/{detalle}', [AlmacenController::class, 'destroyDetalle']);
    Route::post('/almacenes/{almacen}/aplicar', [AlmacenController::class, 'apply']);
    Route::put('/almacenes/{almacen}/anular', [AlmacenController::class, 'cancel']);
    Route::delete('/almacenes/{almacen}', [AlmacenController::class, 'destroy']);

    Route::put('/configuracion', [ConfiguracionController::class, 'update']);
    Route::post('/configuracion/logo', [ConfiguracionController::class, 'uploadLogo']);

    Route::get('/siat/estado', [SiatController::class, 'estado']);
    Route::get('/siat/tokens', [SiatController::class, 'tokens']);
    Route::post('/siat/tokens', [SiatController::class, 'storeToken']);
    Route::delete('/siat/tokens/{token}', [SiatController::class, 'destroyToken']);
    Route::get('/siat/cuis', [SiatController::class, 'cuis']);
    Route::post('/siat/cuis', [SiatController::class, 'generarCuis']);
    Route::get('/siat/cufds', [SiatController::class, 'cufds']);
    Route::post('/siat/cufds', [SiatController::class, 'generarCufd']);
    Route::post('/siat/sincronizar', [SiatController::class, 'sincronizar']);
    Route::get('/siat/catalogos/{tipo}', [SiatController::class, 'catalogo']);
    Route::get('/siat/categorias', [SiatController::class, 'categorias']);
    Route::put('/siat/categorias/{categoria}', [SiatController::class, 'updateCategoria']);
    Route::get('/siat/eventos', [SiatController::class, 'eventos']);
    Route::post('/siat/eventos', [SiatController::class, 'enviarEventos']);
    Route::post('/siat/eventos/{evento}/revalidar', [SiatController::class, 'revalidarEvento']);
});
