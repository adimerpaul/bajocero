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
        $archivo = basename($archivo);
        $path = public_path('images/'.$archivo);
        abort_unless(is_file($path), 404, 'Imagen no encontrada');

        return response()->file($path, [
            'Cache-Control' => 'public, max-age=604800',
            'Access-Control-Allow-Origin' => $request->headers->get('Origin', '*'),
        ]);
    }
}
