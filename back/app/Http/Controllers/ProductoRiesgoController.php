<?php

namespace App\Http\Controllers;

use App\Models\Producto;
use App\Models\ProductoRiesgo;
use Illuminate\Http\Request;

/**
 * Productos en riesgo: lista de seguimiento independiente del stock, de las bajas y del inventario.
 * Agregar crea una fila ACTIVO; quitar la pasa a QUITADO guardando quién y cuándo (nunca se borra).
 */
class ProductoRiesgoController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizeAction($request, 'Ver Productos en Riesgo');
        $status = $request->input('estado', 'ACTIVO');   // ACTIVO | QUITADO | TODOS
        $search = trim((string) $request->input('q', ''));

        $query = ProductoRiesgo::query()
            ->when(in_array($status, ['ACTIVO', 'QUITADO'], true), fn ($q) => $q->where('estado', $status))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('nombre', 'like', "%{$search}%")->orWhere('codigo', 'like', "%{$search}%")
                ->orWhere('lote', 'like', "%{$search}%")->orWhere('observacion', 'like', "%{$search}%")
                ->orWhere('agregado_por_nombre', 'like', "%{$search}%")->orWhere('quitado_por_nombre', 'like', "%{$search}%")))
            ->when($request->filled('producto_id'), fn ($q) => $q->where('producto_id', $request->integer('producto_id')));

        // Primero lo que vence antes (vencidos arriba); sin fecha al final. Empate: lo agregado más reciente.
        $query->orderByRaw('fecha_vencimiento IS NULL')->orderBy('fecha_vencimiento')->orderByDesc('agregado_at');

        $page = $query->orderByDesc('id')->paginate(min(max($request->integer('per_page', 25), 1), 200));

        // En los quitados se avisa si ya hay otro registro activo igual, para no ofrecer "volver a agregar".
        $page->getCollection()->transform(function ($row) {
            $row->ya_activo = $row->estado === 'QUITADO' && $row->producto_id
                && ProductoRiesgo::activoPara($row->producto_id, $row->lote, $row->fecha_vencimiento?->format('Y-m-d')) !== null;

            return $row;
        });

        return response()->json($page->toArray() + [
            'totales' => [
                'ACTIVO' => ProductoRiesgo::where('estado', 'ACTIVO')->count(),
                'QUITADO' => ProductoRiesgo::where('estado', 'QUITADO')->count(),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeAction($request, 'Gestionar Productos en Riesgo');
        $data = $request->validate([
            'producto_id' => ['required', 'exists:productos,id'],
            'lote' => ['nullable', 'string', 'max:100'],
            'fecha_vencimiento' => ['nullable', 'date'],
            'cantidad' => ['nullable', 'numeric', 'min:0', 'decimal:0,3'],
            'origen' => ['nullable', 'in:MANUAL,POR_VENCER,VENCIDO,INVENTARIO'],
            'observacion' => ['nullable', 'string', 'max:1000'],
        ]);
        $product = Producto::findOrFail($data['producto_id']);
        $lot = isset($data['lote']) && trim($data['lote']) !== '' ? mb_strtoupper(trim($data['lote'])) : null;
        $expiry = $data['fecha_vencimiento'] ?? null;

        // El mismo producto/lote/vencimiento no puede estar activo dos veces.
        $existing = ProductoRiesgo::activoPara($product->id, $lot, $expiry);
        abort_if($existing, 409, "{$product->nombre} ya está en riesgo (agregado por {$existing?->agregado_por_nombre})");

        $row = ProductoRiesgo::create([
            'producto_id' => $product->id, 'codigo' => $product->codigo, 'nombre' => $product->nombre,
            'unidad' => $product->unidad, 'foto' => $product->foto,
            'lote' => $lot, 'fecha_vencimiento' => $expiry,
            'cantidad' => isset($data['cantidad']) ? round((float) $data['cantidad'], 3) : null,
            'origen' => $data['origen'] ?? 'MANUAL',
            'observacion' => isset($data['observacion']) ? mb_strtoupper(trim($data['observacion'])) : null,
            'estado' => 'ACTIVO',
            'agregado_por' => $request->user()->id, 'agregado_por_nombre' => $request->user()->name, 'agregado_at' => now(),
        ]);

        return response()->json($row, 201);
    }

    public function quitar(Request $request, ProductoRiesgo $productoRiesgo)
    {
        $this->authorizeAction($request, 'Gestionar Productos en Riesgo');
        $data = $request->validate(['motivo' => ['nullable', 'string', 'max:1000']]);
        abort_if($productoRiesgo->estado !== 'ACTIVO', 422, 'Este producto ya fue quitado');
        $productoRiesgo->update([
            'estado' => 'QUITADO',
            'quitado_por' => $request->user()->id, 'quitado_por_nombre' => $request->user()->name, 'quitado_at' => now(),
            'motivo_quitado' => isset($data['motivo']) && trim($data['motivo']) !== '' ? mb_strtoupper(trim($data['motivo'])) : null,
        ]);

        return response()->json($productoRiesgo->fresh());
    }

    private function authorizeAction(Request $request, string $permission): void
    {
        abort_unless($request->user()?->hasPermissionTo($permission), 403, 'No tiene permiso para realizar esta acción');
    }
}
