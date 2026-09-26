<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Services\Siat\SiatService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ClienteController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizeAction($request, 'Ver Clientes');
        $query = Cliente::query()->withCount('ventas')->orderBy('nombre');
        if ($search = trim((string) $request->input('q'))) {
            $query->where(fn ($q) => $q->where('nombre', 'like', "%{$search}%")
                ->orWhere('numero_documento', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%"));
        }
        $perPage = (int) $request->input('per_page', 20);

        return response()->json($query->paginate($perPage === 0 ? 500 : min(max($perPage, 1), 500)));
    }

    /**
     * Búsqueda de la caja: por número de documento (lo que el cliente dicta) o por
     * nombre. Basta con poder vender.
     */
    public function buscar(Request $request)
    {
        $this->authorizeAction($request, ['Crear Ventas', 'Ver Clientes']);
        $search = trim((string) $request->input('q'));
        if ($search === '') {
            return response()->json([]);
        }

        return response()->json(Cliente::where('numero_documento', 'like', "{$search}%")
            ->orWhere('nombre', 'like', "%{$search}%")
            ->orderByRaw('numero_documento = ? desc', [$search])->orderBy('nombre')->limit(10)->get());
    }

    /** Consulta el NIT en el padrón del SIN. `valido` es null si Impuestos no respondió. */
    public function verificarNit(Request $request, SiatService $siat)
    {
        $this->authorizeAction($request, ['Crear Ventas', 'Ver Clientes']);
        $data = $request->validate(['nit' => ['required', 'string', 'max:20']]);

        return response()->json(['valido' => $siat->verificarNit(trim($data['nit']))]);
    }

    public function store(Request $request)
    {
        $this->authorizeAction($request, 'Crear Clientes');
        $data = $this->validated($request);
        // El documento es único también entre los eliminados: se revive el anterior.
        $deleted = Cliente::onlyTrashed()->where($request->only('tipo_documento', 'numero_documento'))->where('complemento', $data['complemento'])->first();
        if ($deleted) {
            $deleted->restore();
            $deleted->update($data);

            return response()->json($deleted->fresh(), 201);
        }

        return response()->json(Cliente::create($data), 201);
    }

    public function update(Request $request, Cliente $cliente)
    {
        $this->authorizeAction($request, 'Editar Clientes');
        $cliente->update($this->validated($request, $cliente));

        return response()->json($cliente->fresh());
    }

    public function destroy(Request $request, Cliente $cliente)
    {
        $this->authorizeAction($request, 'Eliminar Clientes');
        $cliente->delete();

        return response()->noContent();
    }

    private function validated(Request $request, ?Cliente $cliente = null): array
    {
        $request->merge([
            'numero_documento' => trim((string) $request->input('numero_documento')),
            'complemento' => mb_strtoupper(trim((string) $request->input('complemento'))),
            'nombre' => mb_strtoupper(trim((string) $request->input('nombre'))),
        ]);
        $data = $request->validate([
            'tipo_documento' => ['required', Rule::in(array_keys(Cliente::TIPOS_DOCUMENTO))],
            'numero_documento' => ['required', 'string', 'max:20', Rule::unique('clientes')->where(fn ($q) => $q
                ->where('tipo_documento', $request->input('tipo_documento'))
                ->where('complemento', $request->input('complemento'))->whereNull('deleted_at'))->ignore($cliente?->id)],
            'complemento' => ['nullable', 'string', 'max:5'],
            'nombre' => ['required', 'string', 'max:500'],
            'email' => ['nullable', 'email', 'max:255'],
            'telefono' => ['nullable', 'string', 'max:80'],
            'direccion' => ['nullable', 'string', 'max:255'],
        ], ['numero_documento.unique' => 'Ya existe un cliente con ese documento']);
        $data['complemento'] = $data['complemento'] ?? '';

        return $data;
    }

    /** @param  string|string[]  $permission  Con varios, alcanza con tener uno. */
    private function authorizeAction(Request $request, string|array $permission): void
    {
        $user = $request->user();
        $allowed = collect((array) $permission)->contains(fn ($name) => (bool) $user?->hasPermissionTo($name));
        abort_unless($allowed, 403, 'No tiene permiso para realizar esta acción');
    }
}
