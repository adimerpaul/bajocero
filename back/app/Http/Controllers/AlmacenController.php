<?php

namespace App\Http\Controllers;

use App\Models\Almacen;
use App\Models\AlmacenDetalle;
use App\Models\Lote;
use App\Models\Producto;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Revisión física del stock de la tienda. El documento se llena entre varias
 * personas (cada una carga productos distintos desde su celular) y al aplicarlo
 * el stock del sistema pasa a ser exactamente lo contado, guardando por línea el
 * valor antiguo y el nuevo.
 */
class AlmacenController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizeAction($request, 'Ver Almacenes');

        return response()->json($this->filteredQuery($request)->withCount('detalles')->latest('fecha')
            ->paginate(min(max((int) $request->input('per_page', 20), 1), 200)));
    }

    public function summary(Request $request)
    {
        $this->authorizeAction($request, 'Ver Almacenes');
        $query = $this->filteredQuery($request);

        return response()->json([
            'en_revision' => (clone $query)->where('estado', 'BORRADOR')->count(),
            'aplicados' => (clone $query)->where('estado', 'APLICADO')->count(),
            'productos_revisados' => (int) AlmacenDetalle::whereIn('almacen_id', (clone $query)->select('id'))->count(),
            'diferencia_valor' => (float) AlmacenDetalle::whereIn('almacen_id', (clone $query)->where('estado', 'APLICADO')->select('id'))
                ->selectRaw('COALESCE(SUM(diferencia * precio_compra), 0) as total')->value('total'),
        ]);
    }

    /** Sólo la cabecera: el detalle se pide paginado en detalles(), así el sondeo de cada celular pesa poco. */
    public function show(Request $request, Almacen $almacen)
    {
        $this->authorizeAction($request, 'Ver Almacenes');

        return response()->json($this->withDetails($almacen));
    }

    /**
     * Líneas contadas, paginadas. Cada una trae stock_actual (el del sistema con el
     * que se compara) y diferencia_actual calculados en SQL, para poder filtrar sólo
     * diferencias sin bajar todo el documento.
     */
    public function detalles(Request $request, Almacen $almacen)
    {
        $this->authorizeAction($request, 'Ver Almacenes');
        [$query, $system] = $this->detailsQuery($almacen);
        $query->select('almacen_detalles.*')
            ->selectRaw("{$system} as stock_actual")
            ->selectRaw("ROUND(almacen_detalles.cantidad - {$system}, 3) as diferencia_actual")
            ->with('conteos');

        if ($search = trim((string) $request->input('q'))) {
            $query->where(fn ($q) => $q->where('almacen_detalles.nombre', 'like', "%{$search}%")
                ->orWhere('almacen_detalles.codigo', 'like', "%{$search}%")
                ->orWhere('almacen_detalles.usuario_nombre', 'like', "%{$search}%"));
        }
        if ($ids = array_filter(array_map('intval', (array) $request->input('producto_ids', [])))) {
            $query->whereIn('almacen_detalles.producto_id', $ids);
        }
        if ($request->boolean('solo_diferencias')) {
            $query->whereRaw("ABS(almacen_detalles.cantidad - {$system}) > 0.0001");
        }
        $request->input('orden') === 'nombre'
            ? $query->orderBy('almacen_detalles.nombre')->orderBy('almacen_detalles.id')
            : $query->orderByDesc('almacen_detalles.id');

        $page = $query->paginate(min(max((int) $request->input('per_page', 20), 1), 100));

        // El estado viaja en el sondeo para enterarse si otro ya aplicó o anuló la revisión.
        return response()->json(['estado' => $almacen->estado] + $page->toArray());
    }

    /** Vista de avance: totales calculados en SQL; las líneas se piden aparte, paginadas, en detalles(). */
    public function progress(Request $request, Almacen $almacen)
    {
        $this->authorizeAction($request, 'Ver Almacenes');
        [$query, $system] = $this->detailsQuery($almacen);
        $diff = "ROUND(almacen_detalles.cantidad - {$system}, 3)";
        $totals = $query->selectRaw("COUNT(*) as revisados,
            COALESCE(SUM(CASE WHEN ABS({$diff}) > 0.0001 THEN 1 ELSE 0 END), 0) as con_diferencia,
            COALESCE(SUM(CASE WHEN {$diff} > 0.0001 THEN 1 ELSE 0 END), 0) as sobrantes,
            COALESCE(SUM(CASE WHEN {$diff} > 0.0001 THEN {$diff} ELSE 0 END), 0) as sobrante_total,
            COALESCE(SUM(CASE WHEN {$diff} < -0.0001 THEN 1 ELSE 0 END), 0) as faltantes,
            COALESCE(SUM(CASE WHEN {$diff} < -0.0001 THEN -{$diff} ELSE 0 END), 0) as faltante_total,
            COALESCE(SUM({$diff} * almacen_detalles.precio_compra), 0) as diferencia_valor")->first();

        return response()->json([
            'almacen' => $almacen,
            'total_productos' => Producto::count(),
            'revisados' => (int) $totals->revisados,
            'con_diferencia' => (int) $totals->con_diferencia,
            'sin_diferencia' => (int) $totals->revisados - (int) $totals->con_diferencia,
            'sobrantes' => ['count' => (int) $totals->sobrantes, 'total' => round((float) $totals->sobrante_total, 3)],
            'faltantes' => ['count' => (int) $totals->faltantes, 'total' => round((float) $totals->faltante_total, 3)],
            'diferencia_valor' => round((float) $totals->diferencia_valor, 2),
            'por_usuario' => $almacen->detalles()->selectRaw('usuario_nombre, COUNT(*) as productos')
                ->groupBy('usuario_nombre')->orderByDesc('productos')->get()
                ->map(fn ($row) => ['usuario' => $row->usuario_nombre ?: '—', 'productos' => (int) $row->productos]),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeAction($request, 'Crear Almacenes');
        $data = $request->validate([
            'descripcion' => ['nullable', 'string', 'max:255'],
            'observacion' => ['nullable', 'string', 'max:1000'],
        ]);

        $almacen = Almacen::create([
            'user_id' => $request->user()->id,
            'usuario_nombre' => $request->user()->name,
            'descripcion' => $data['descripcion'] ?? null,
            'observacion' => $data['observacion'] ?? null,
            'estado' => 'BORRADOR',
            'fecha' => now(),
        ]);
        $almacen->update(['numero' => 'A-'.str_pad((string) $almacen->id, 8, '0', STR_PAD_LEFT)]);

        return response()->json($this->withDetails($almacen->fresh()), 201);
    }

    public function update(Request $request, Almacen $almacen)
    {
        $this->authorizeAction($request, 'Editar Almacenes');
        abort_unless($almacen->editable(), 422, 'Sólo se puede modificar un almacén en revisión');
        $almacen->update($request->validate([
            'descripcion' => ['nullable', 'string', 'max:255'],
            'observacion' => ['nullable', 'string', 'max:1000'],
        ]));

        return response()->json($this->withDetails($almacen->fresh()));
    }

    /**
     * Cuenta un producto. Cada línea se guarda sola, así varias personas cargan
     * productos distintos en el mismo almacén sin pisarse.
     */
    public function storeDetalle(Request $request, Almacen $almacen)
    {
        $this->authorizeAction($request, 'Editar Almacenes');
        abort_unless($almacen->editable(), 422, 'Este almacén ya fue aplicado o anulado');
        $data = $this->validatedLine($request);

        $detail = DB::transaction(function () use ($request, $almacen, $data) {
            $product = Producto::findOrFail($data['producto_id']);
            $existing = $almacen->detalles()->where('producto_id', $product->id)->first();
            abort_if($existing && ! $request->boolean('reemplazar'), 409,
                "{$existing?->usuario_nombre} ya contó {$product->nombre} con ".rtrim(rtrim(number_format((float) $existing?->cantidad, 3, '.', ''), '0'), '.'));

            $payload = $this->linePayload($product, $data) + [
                'user_id' => $request->user()->id,
                'usuario_nombre' => $request->user()->name,
            ];
            $detail = $existing ? tap($existing)->update($payload) : $almacen->detalles()->create($payload);
            $this->syncConteos($detail, $data);
            $this->refreshTotals($almacen);

            return $detail;
        });

        return response()->json($detail->fresh()->load('conteos'), 201);
    }

    public function updateDetalle(Request $request, Almacen $almacen, AlmacenDetalle $detalle)
    {
        $this->authorizeAction($request, 'Editar Almacenes');
        abort_unless($almacen->editable(), 422, 'Este almacén ya fue aplicado o anulado');
        abort_unless($detalle->almacen_id === $almacen->id, 404);
        $data = $this->validatedLine($request, $detalle);

        DB::transaction(function () use ($almacen, $detalle, $data) {
            $detalle->update($this->linePayload(Producto::findOrFail($data['producto_id']), $data));
            $this->syncConteos($detalle, $data);
            $this->refreshTotals($almacen);
        });

        return response()->json($detalle->fresh()->load('conteos'));
    }

    public function destroyDetalle(Request $request, Almacen $almacen, AlmacenDetalle $detalle)
    {
        $this->authorizeAction($request, 'Editar Almacenes');
        abort_unless($almacen->editable(), 422, 'Este almacén ya fue aplicado o anulado');
        abort_unless($detalle->almacen_id === $almacen->id, 404);

        DB::transaction(function () use ($almacen, $detalle) {
            $detalle->delete();
            $this->refreshTotals($almacen);
        });

        return response()->json(['message' => 'Producto quitado de la revisión']);
    }

    /**
     * Botón "Actualizar productos": el stock de cada producto contado pasa a ser
     * la cantidad de la revisión. Los productos que nadie contó no se tocan.
     */
    public function apply(Request $request, Almacen $almacen)
    {
        $this->authorizeAction($request, 'Aplicar Almacenes');
        abort_unless($almacen->estado === 'BORRADOR', 422, 'Este almacén ya fue aplicado o anulado');
        abort_if($almacen->detalles()->count() === 0, 422, 'Cuente al menos un producto antes de actualizar el inventario');

        DB::transaction(function () use ($request, $almacen) {
            foreach ($almacen->detalles as $detail) {
                $product = Producto::lockForUpdate()->find($detail->producto_id);
                abort_unless($product, 422, "El producto {$detail->nombre} ya no existe; quítelo de la revisión");

                $before = round((float) $product->stock_inicial, 3);
                $counted = round((float) $detail->cantidad, 3);
                $difference = round($counted - $before, 3);
                $countedLots = $detail->conteos;

                if ($countedLots->isNotEmpty()) {
                    // Se contaron los lotes uno por uno: eso es la verdad, se reemplazan los del sistema.
                    $this->replaceLots($detail, $product, $countedLots);
                } elseif ($difference > 0.0001) {
                    Lote::create([
                        'producto_id' => $product->id,
                        'almacen_detalle_id' => $detail->id,
                        'lote' => $detail->lote,
                        'fecha_vencimiento' => $detail->fecha_vencimiento,
                        'cantidad_inicial' => $difference,
                        'cantidad_disponible' => $difference,
                    ]);
                } elseif ($difference < -0.0001) {
                    $this->consumeLots($detail->id, $product->id, abs($difference));
                }

                $product->update(['stock_inicial' => $counted]);
                $detail->update([
                    'stock_anterior' => $before,
                    'stock_nuevo' => $counted,
                    'diferencia' => $difference,
                    'total' => round($counted * (float) $detail->precio_compra, 2),
                ]);
            }

            $almacen->update([
                'estado' => 'APLICADO',
                'fecha_aplicado' => now(),
                'aplicado_por' => $request->user()->id,
                'aplicado_por_nombre' => $request->user()->name,
            ]);
            $this->refreshTotals($almacen);
        });

        return response()->json($this->withDetails($almacen->fresh()));
    }

    /** Anula: si ya estaba aplicado deshace el ajuste de cada producto. */
    public function cancel(Request $request, Almacen $almacen)
    {
        $this->authorizeAction($request, 'Anular Almacenes');
        abort_if($almacen->estado === 'ANULADO', 422, 'El almacén ya está anulado');

        DB::transaction(function () use ($almacen) {
            if ($almacen->estado === 'APLICADO') {
                foreach ($almacen->detalles as $detail) {
                    $difference = round((float) $detail->diferencia, 3);
                    $product = Producto::lockForUpdate()->find($detail->producto_id);

                    // Los lotes que creó la revisión tienen que seguir intactos para poder deshacerla.
                    $created = Lote::where('almacen_detalle_id', $detail->id)->lockForUpdate()->get();
                    foreach ($created as $lot) {
                        abort_if(round((float) $lot->cantidad_inicial - (float) $lot->cantidad_disponible, 3) > 0.0001, 422,
                            "No se puede anular: ya se usó stock del lote de {$detail->nombre}");
                    }
                    abort_if($product && $difference > 0.0001 && (float) $product->stock_inicial + 0.0001 < $difference, 422,
                        "No se puede anular: el stock de {$detail->nombre} ya fue utilizado");

                    foreach ($created as $lot) {
                        $lot->delete();
                    }
                    foreach (DB::table('almacen_detalle_lotes')->where('almacen_detalle_id', $detail->id)->get() as $allocation) {
                        Lote::whereKey($allocation->lote_id)->increment('cantidad_disponible', $allocation->cantidad);
                    }
                    DB::table('almacen_detalle_lotes')->where('almacen_detalle_id', $detail->id)->delete();

                    $product?->decrement('stock_inicial', $difference);
                }
            }

            $almacen->update(['estado' => 'ANULADO']);
        });

        return response()->json($this->withDetails($almacen->fresh()));
    }

    public function destroy(Request $request, Almacen $almacen)
    {
        $this->authorizeAction($request, 'Anular Almacenes');
        abort_unless($almacen->editable(), 422, 'Sólo se puede eliminar un almacén en revisión');
        $almacen->delete();

        return response()->json(['message' => 'Almacén eliminado']);
    }

    /**
     * Deja los lotes del producto tal cual se contaron: guarda el saldo de los lotes
     * vigentes (para poder reponerlos al anular), los pone en cero y crea uno por
     * cada lote contado. Los lotes viejos no se borran para no perder el historial
     * de ventas que los referencia.
     */
    private function replaceLots(AlmacenDetalle $detail, Producto $product, $countedLots): void
    {
        $current = Lote::where('producto_id', $product->id)->where('cantidad_disponible', '>', 0)
            ->lockForUpdate()->get();

        foreach ($current as $lot) {
            DB::table('almacen_detalle_lotes')->insert([
                'almacen_detalle_id' => $detail->id, 'lote_id' => $lot->id,
                'cantidad' => $lot->cantidad_disponible, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $lot->update(['cantidad_disponible' => 0]);
        }

        foreach ($countedLots as $countedLot) {
            Lote::create([
                'producto_id' => $product->id,
                'almacen_detalle_id' => $detail->id,
                'lote' => $countedLot->lote,
                'fecha_vencimiento' => $countedLot->fecha_vencimiento,
                'cantidad_inicial' => $countedLot->cantidad,
                'cantidad_disponible' => $countedLot->cantidad,
            ]);
        }
    }

    /** Descuenta la faltante por FIFO de vencimiento, igual que una venta. */
    private function consumeLots(int $detailId, int $productId, float $quantity): void
    {
        $remaining = $quantity;
        $lots = Lote::where('producto_id', $productId)->where('cantidad_disponible', '>', 0)
            ->orderByRaw('fecha_vencimiento IS NULL')
            ->orderBy('fecha_vencimiento')->orderBy('id')->lockForUpdate()->get();

        foreach ($lots as $lot) {
            if ($remaining <= 0.0001) {
                break;
            }
            $taken = min($remaining, (float) $lot->cantidad_disponible);
            $lot->decrement('cantidad_disponible', $taken);
            DB::table('almacen_detalle_lotes')->insert([
                'almacen_detalle_id' => $detailId, 'lote_id' => $lot->id,
                'cantidad' => $taken, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $remaining = round($remaining - $taken, 3);
        }
    }

    private function validatedLine(Request $request, ?AlmacenDetalle $detalle = null): array
    {
        $data = $request->validate([
            'producto_id' => [$detalle ? 'nullable' : 'required', 'integer', 'exists:productos,id'],
            'cantidad' => ['required_without:conteos', 'nullable', 'numeric', 'min:0', 'decimal:0,3'],
            'lote' => ['nullable', 'string', 'max:100'],
            'fecha_vencimiento' => ['nullable', 'date'],
            'observacion' => ['nullable', 'string', 'max:255'],
            'conteos' => ['nullable', 'array'],
            'conteos.*.lote' => ['nullable', 'string', 'max:100'],
            'conteos.*.fecha_vencimiento' => ['nullable', 'date'],
            'conteos.*.cantidad' => ['required', 'numeric', 'min:0.001', 'decimal:0,3'],
        ]);
        $data['producto_id'] ??= $detalle?->producto_id;

        return $data;
    }

    /** Con lotes cargados la cantidad del producto es la suma de ellos. */
    private function countedQuantity(array $data): float
    {
        $lots = $data['conteos'] ?? [];

        return $lots === []
            ? round((float) ($data['cantidad'] ?? 0), 3)
            : round(array_sum(array_map(fn ($lot) => (float) $lot['cantidad'], $lots)), 3);
    }

    private function linePayload(Producto $product, array $data): array
    {
        $counted = $this->countedQuantity($data);
        $lots = $data['conteos'] ?? [];

        return [
            'producto_id' => $product->id,
            'codigo' => $product->codigo,
            'nombre' => $product->nombre,
            'unidad' => $product->unidad,
            'foto' => $product->foto,
            'stock_sistema' => round((float) $product->stock_inicial, 3),
            'cantidad' => $counted,
            // Con un solo lote se copia a la cabecera de la línea para poder mostrarla sin abrir el detalle.
            'lote' => count($lots) === 1 ? ($lots[0]['lote'] ?? null) : ($lots === [] ? ($data['lote'] ?? null) : null),
            'fecha_vencimiento' => count($lots) === 1 ? ($lots[0]['fecha_vencimiento'] ?? null) : ($lots === [] ? ($data['fecha_vencimiento'] ?? null) : null),
            'precio_compra' => $product->precio_compra,
            'total' => round($counted * (float) $product->precio_compra, 2),
            'observacion' => $data['observacion'] ?? null,
        ];
    }

    private function syncConteos(AlmacenDetalle $detalle, array $data): void
    {
        $detalle->conteos()->delete();
        foreach ($data['conteos'] ?? [] as $lot) {
            $detalle->conteos()->create([
                'lote' => $lot['lote'] ?? null,
                'fecha_vencimiento' => $lot['fecha_vencimiento'] ?? null,
                'cantidad' => round((float) $lot['cantidad'], 3),
            ]);
        }
    }

    private function refreshTotals(Almacen $almacen): void
    {
        $details = $almacen->detalles()->get();
        $almacen->update([
            'total_cantidad' => round((float) $details->sum(fn ($d) => (float) $d->cantidad), 3),
            'total_costo' => round((float) $details->sum(fn ($d) => (float) $d->total), 2),
        ]);
    }

    /** Cabecera con la cantidad de líneas; el detalle no viaja aquí porque puede pesar cientos de KB. */
    private function withDetails(Almacen $almacen): Almacen
    {
        return $almacen->loadCount('detalles');
    }

    /**
     * Líneas del almacén unidas a su producto. Se compara contra el stock guardado al
     * aplicar, o contra el stock vivo del producto mientras está en revisión (si el
     * producto se eliminó, el que se guardó al contar).
     */
    private function detailsQuery(Almacen $almacen): array
    {
        $system = $almacen->estado === 'APLICADO'
            ? 'COALESCE(almacen_detalles.stock_anterior, 0)'
            : 'COALESCE(productos.stock_inicial, almacen_detalles.stock_sistema, 0)';
        $query = AlmacenDetalle::query()->where('almacen_detalles.almacen_id', $almacen->id)
            ->leftJoin('productos', fn ($join) => $join->on('productos.id', '=', 'almacen_detalles.producto_id')->whereNull('productos.deleted_at'));

        return [$query, $system];
    }

    private function filteredQuery(Request $request)
    {
        $query = Almacen::query();

        if ($search = trim((string) $request->input('q'))) {
            $query->where(fn ($q) => $q->where('numero', 'like', "%{$search}%")
                ->orWhere('usuario_nombre', 'like', "%{$search}%")
                ->orWhere('descripcion', 'like', "%{$search}%")
                ->orWhere('observacion', 'like', "%{$search}%"));
        }
        if ($from = $request->date('desde')) {
            $query->whereDate('fecha', '>=', $from);
        }
        if ($to = $request->date('hasta')) {
            $query->whereDate('fecha', '<=', $to);
        }
        if ($estado = trim((string) $request->input('estado'))) {
            $query->where('estado', $estado);
        }

        return $query;
    }

    private function authorizeAction(Request $request, string $permission): void
    {
        abort_unless($request->user()?->hasPermissionTo($permission), 403, 'No tiene permiso para realizar esta acción');
    }
}
