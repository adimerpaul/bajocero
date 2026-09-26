<?php

declare(strict_types=1);

date_default_timezone_set('America/La_Paz');

/**
 * Configuracion SIAT centralizada.
 * Ajusta aqui CUIS/CUFD por punto de venta (0 y 1).
 */
function obtenerDatosSiat(int $codigoPuntoVenta): array
{
    $config = [
        'nit' => '7308976010',
        'token' => 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzUxMiJ9.eyJzdWIiOiJmcmFuenNhbnRvc2JsYW5jb0BnbWFpbC5jb20iLCJjb2RpZ29TaXN0ZW1hIjoiMjI4NTRDREM4M0I0MTcwQkJGMkM2Iiwibml0IjoiSDRzSUFBQUFBQUFBQURNM05yQ3dORGN6TURRQUFHanlnMEVLQUFBQSIsImlkIjo1MjE5MzQ0LCJleHAiOjE3OTg3MDAwNDIsImlhdCI6MTc4ODE3MzYxMiwibml0RGVsZWdhZG8iOjczMDg5NzYwMTAsInN1YnNpc3RlbWEiOiJTRkUifQ.XLZnYTKpAj5vkND0I76XMxI7Joi7wUEbCpCG1dtHeKRqsk9K6EDZQWvtOi6QJ5t1Qtk-rH8y3DXVxmlewIGR3A',
        'codigoAmbiente' => 2,
        'codigoSistema' => '22854CDC83B4170BBF2C6',
        'codigoSucursal' => 0,
        'codigoModalidad' => 2,
        'puntosVenta' => [
            0 => [
                'cuis' => '19E5079E',
                'cufd' => 'VBQUFBQi9fZUhBI0MTcwQkJGMkM2Q3nDmlhHRGJKYVMjI4NTRDREM4M0',
                'codigoControl' => '93D430E2743BF74',
            ],
            1 => [
                'cuis' => '619326BD',
                'cufd' => 'JBQUFCL19lSEE=I0MTcwQkJGMkM2Q0ttekdEYkphVUMjI4NTRDREM4M0',
                'codigoControl' => '35FF90E2743BF74',
            ],
        ],
    ];

    if (!isset($config['puntosVenta'][$codigoPuntoVenta])) {
        throw new InvalidArgumentException('Punto de venta no configurado: ' . $codigoPuntoVenta);
    }

    $puntoVenta = $config['puntosVenta'][$codigoPuntoVenta];

    return [
        'nit' => $config['nit'],
        'token' => $config['token'],
        'codigoAmbiente' => $config['codigoAmbiente'],
        'codigoSistema' => $config['codigoSistema'],
        'codigoSucursal' => $config['codigoSucursal'],
        'codigoModalidad' => $config['codigoModalidad'],
        'codigoPuntoVenta' => $codigoPuntoVenta,
        'cuis' => $puntoVenta['cuis'],
        'cufd' => $puntoVenta['cufd'],
        'codigoControl' => $puntoVenta['codigoControl'],
    ];
}
