<?php

/*
 * Facturación en línea SIAT — modalidad COMPUTARIZADA (2): el XML no se firma.
 *
 * Las credenciales de pruebas piloto viven en back/.env (no se versiona). El token
 * también se puede registrar desde la pantalla Impuestos; el guardado en la base de
 * datos tiene prioridad sobre SIAT_TOKEN.
 */
return [
    'enabled' => (bool) env('SIAT_ENABLED', true),
    // 2 = pruebas piloto, 1 = producción.
    'ambiente' => (int) env('SIAT_AMBIENTE', 2),
    'modalidad' => (int) env('SIAT_MODALIDAD', 2),
    'base_url' => rtrim(env('SIAT_URL', 'https://pilotosiatservicios.impuestos.gob.bo/v2/'), '/').'/',
    // Página pública de consulta que va en el QR de la factura.
    'qr_url' => env('SIAT_QR_URL', 'https://pilotosiat.impuestos.gob.bo/consulta/QR'),
    'codigo_sistema' => env('SIAT_CODIGO_SISTEMA'),
    'nit' => env('SIAT_NIT'),
    'token' => env('SIAT_TOKEN'),
    'sucursal' => (int) env('SIAT_SUCURSAL', 0),
    'punto_venta' => (int) env('SIAT_PUNTO_VENTA', 0),
    'municipio' => env('SIAT_MUNICIPIO', 'Oruro'),
    // Actividad principal (tipo P) y código SIN para productos sin categoría clasificada.
    'actividad_economica' => env('SIAT_ACTIVIDAD_ECONOMICA', '4711100'),
    'codigo_producto_sin' => (int) env('SIAT_CODIGO_PRODUCTO_SIN', 1004690),
    // Unidades de medida paramétricas: 22 KILOGRAMO, 57 UNIDAD (BIENES).
    'unidad_kg' => 22,
    'unidad_pieza' => 57,
    // Facturas por paquete de evento significativo (tope del SIN).
    'paquete_maximo' => 500,
];
