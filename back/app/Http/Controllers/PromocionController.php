<?php

namespace App\Http\Controllers;

use App\Models\Promocion;
use Illuminate\Http\Request;

class PromocionController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizeAction($request, 'Ver Promociones');

        return response()->json(Promocion::orderByDesc('estado')->orderBy('monto')->get());
    }

    /** Lo que necesita la caja para imprimir cupones (también se guarda para vender sin conexión). */
    public function activas(Request $request)
    {
        $this->authorizeAction($request, ['Crear Ventas', 'Crear Ventas Offline', 'Ver Promociones']);

        return response()->json(Promocion::where('estado', true)->orderBy('monto')
            ->get(['id', 'nombre', 'titulo', 'subtitulo', 'monto', 'valor_ficha', 'impresiones']));
    }

    public function store(Request $request)
    {
        $this->authorizeAction($request, 'Crear Promociones');

        return response()->json(Promocion::create($this->validated($request)), 201);
    }

    public function update(Request $request, Promocion $promocion)
    {
        $this->authorizeAction($request, 'Editar Promociones');
        $promocion->update($this->validated($request));

        return response()->json($promocion->fresh());
    }

    public function destroy(Request $request, Promocion $promocion)
    {
        $this->authorizeAction($request, 'Eliminar Promociones');
        $promocion->delete();

        return response()->noContent();
    }

    private function validated(Request $request): array
    {
        $request->merge([
            'nombre' => mb_strtoupper(trim((string) $request->input('nombre'))),
            'titulo' => mb_strtoupper(trim((string) $request->input('titulo'))),
            'subtitulo' => mb_strtoupper(trim((string) $request->input('subtitulo'))) ?: null,
            'valor_ficha' => $request->input('valor_ficha') ?: null,
            'impresiones' => $request->input('impresiones') ?: 1,
        ]);

        return $request->validate([
            'nombre' => ['required', 'string', 'max:120'],
            'titulo' => ['required', 'string', 'max:120'],
            'subtitulo' => ['nullable', 'string', 'max:255'],
            'monto' => ['required', 'numeric', 'min:0.01', 'max:9999999'],
            'valor_ficha' => ['nullable', 'numeric', 'min:0.01', 'max:9999999'],
            'impresiones' => ['required', 'integer', 'min:1', 'max:5'],
            'estado' => ['required', 'boolean'],
        ]);
    }

    /** @param  string|string[]  $permission  Con varios, alcanza con tener uno. */
    private function authorizeAction(Request $request, string|array $permission): void
    {
        $user = $request->user();
        $allowed = collect((array) $permission)->contains(fn ($name) => (bool) $user?->hasPermissionTo($name));
        abort_unless($allowed, 403, 'No tiene permiso para realizar esta acción');
    }
}
