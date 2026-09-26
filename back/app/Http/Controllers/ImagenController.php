<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

/**
 * Entrega las imágenes de public/images a través del API.
 *
 * El frontend las descarga para guardarlas en base64 y poder mostrarlas sin
 * conexión; hecho contra public/images directamente eso no funciona, porque esos
 * archivos los sirve el servidor web sin cabeceras CORS y el navegador bloquea la
 * lectura desde otro dominio. Saliendo por /api sí pasan por el middleware de CORS.
 */
class ImagenController extends Controller
{
    public function show(Request $request, string $archivo)
    {
        // Puede venir con carpeta (empresa/logo.webp, productos/...), pero nunca fuera de public/images.
        $base = realpath(public_path('images'));
        $path = realpath(public_path('images/'.$archivo));
        abort_unless($path && str_starts_with($path, $base.DIRECTORY_SEPARATOR) && is_file($path), 404, 'Imagen no encontrada');

        return response()->file($path, [
            'Cache-Control' => 'public, max-age=604800',
            'Access-Control-Allow-Origin' => $request->headers->get('Origin', '*'),
        ]);
    }
}
