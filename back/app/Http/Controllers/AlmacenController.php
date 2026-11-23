<?php

namespace App\Http\Controllers;

use App\Models\Almacen;
use App\Models\AlmacenDetalle;
use App\Models\AlmacenDetalleUsuario;
use App\Models\Lote;
use App\Models\Producto;
use App\Models\ProductoRiesgo;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Revisión física del stock de la tienda. El documento se llena entre varias
 * personas desde su celular; si dos cuentan el mismo producto (cada una en un lugar
 * distinto de la tienda) cada conteo se guarda aparte en `almacen_detalle_usuarios`
 * y lo contado del producto es la suma. Al aplicarlo el stock del sistema pasa a ser
 * exactamente ese total, guardando por línea el valor antiguo y el nuevo.
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
            ->with(['conteos', 'usuarios.conteos']);

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
        if ($request->filled('ordenar')) {
            $this->sortDetails($query, $system, (string) $request->input('ordenar'), $request->input('direccion') === 'desc' ? 'desc' : 'asc');
        } else {
            $request->input('orden') === 'nombre'
                ? $query->orderBy('almacen_detalles.nombre')->orderBy('almacen_detalles.id')
                : $query->orderByDesc('almacen_detalles.id');
        }

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
            'por_usuario' => $this->countsByUser($almacen),
        ]);
    }

    /**
     * Excel de avance armado en el navegador por bloques: una fila compacta por producto
     * con lo que contó cada usuario, para el formato Producto | usuario… | Total | Sistema.
     */
    public function exportData(Request $request, Almacen $almacen)
    {
        $this->authorizeAction($request, 'Ver Almacenes');
        $page = max((int) $request->input('page', 1), 1);
        $size = min(max((int) $request->input('per_page', 2000), 50), 5000);
        [$query, $system] = $this->detailsQuery($almacen);
        $query->select('almacen_detalles.*')->selectRaw("{$system} as stock_actual")
            ->with(['usuarios:id,almacen_detalle_id,user_id,usuario_nombre,cantidad', 'conteos:id,almacen_detalle_id,lote,fecha_vencimiento,cantidad'])
            ->orderBy('almacen_detalles.nombre')->orderBy('almacen_detalles.id');
        $total = $page === 1 ? (clone $query)->count() : null;

        $rows = $query->forPage($page, $size)->get()->map(fn ($d) => [
            $d->codigo, $d->nombre, $d->unidad,
            $d->usuarios->map(fn ($u) => [$u->user_id, $u->usuario_nombre, (float) $u->cantidad])->values(),
            (float) $d->cantidad, (float) $d->stock_actual, (float) $d->precio_compra,
            $d->conteos->map(fn ($c) => trim(($c->lote ?: 'S/L').' '.($c->fecha_vencimiento?->format('d/m/Y') ?? '')).' = '.rtrim(rtrim(number_format((float) $c->cantidad, 3, '.', ''), '0'), '.'))->implode('; '),
        ]);

        return response()->json([
            'page' => $page, 'per_page' => $size, 'total' => $total, 'data' => $rows,
            'usuarios' => $page === 1 ? $this->countsByUser($almacen) : null,
        ]);
    }

    /** Cuántos productos contó cada usuario y cuánto sumó, para el avance y las columnas del reporte. */
    private function countsByUser(Almacen $almacen)
    {
        return AlmacenDetalleUsuario::query()
            ->join('almacen_detalles', 'almacen_detalles.id', '=', 'almacen_detalle_usuarios.almacen_detalle_id')
            ->where('almacen_detalles.almacen_id', $almacen->id)
            ->selectRaw('almacen_detalle_usuarios.user_id, MAX(almacen_detalle_usuarios.usuario_nombre) as usuario,
                COUNT(*) as productos, COALESCE(SUM(almacen_detalle_usuarios.cantidad), 0) as cantidad')
            ->groupBy('almacen_detalle_usuarios.user_id')->orderByDesc('productos')->get()
            ->map(fn ($row) => [
                'user_id' => $row->user_id, 'usuario' => $row->usuario ?: '—',
                'productos' => (int) $row->productos, 'cantidad' => round((float) $row->cantidad, 3),
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
     * Guarda el conteo del usuario que llama. Si otra persona ya contó el producto no se
     * pisa: cada uno tiene su conteo y lo contado del producto es la suma. Volver a
     * contar el mismo producto reemplaza sólo el conteo propio.
     */
    public function storeDetalle(Request $request, Almacen $almacen)
    {
        $this->authorizeAction($request, 'Editar Almacenes');
        abort_unless($almacen->editable(), 422, 'Este almacén ya fue aplicado o anulado');
        $data = $this->validatedLine($request);

        $detail = DB::transaction(function () use ($request, $almacen, $data) {
            // Se bloquea la revisión para que dos celulares no creen a la vez la línea del mismo producto.
            Almacen::whereKey($almacen->id)->lockForUpdate()->first();
            $product = Producto::findOrFail($data['producto_id']);
            $detail = $almacen->detalles()->where('producto_id', $product->id)->first()
                ?? $almacen->detalles()->create($this->productSnapshot($product) + ['cantidad' => 0]);
            $row = $detail->usuarios()->firstOrNew(['user_id' => $request->user()->id]);
            $this->saveUserCount($detail, $row, $request->user()->name, $data);
            $this->recalculateLine($detail, $product);
            $this->refreshTotals($almacen);

            return $detail;
        });

        return response()->json($detail->fresh()->load(['conteos', 'usuarios.conteos']), 201);
    }

    /**
     * Corrige un conteo: el de `usuario_conteo_id` si se indica (p. ej. el encargado
     * arregla lo que cargó otro), si no el del usuario que llama.
     */
    public function updateDetalle(Request $request, Almacen $almacen, AlmacenDetalle $detalle)
    {
        $this->authorizeAction($request, 'Editar Almacenes');
        abort_unless($almacen->editable(), 422, 'Este almacén ya fue aplicado o anulado');
        abort_unless($detalle->almacen_id === $almacen->id, 404);
        $data = $this->validatedLine($request, $detalle);

        DB::transaction(function () use ($request, $almacen, $detalle, $data) {
            $row = ($rowId = (int) ($data['usuario_conteo_id'] ?? 0))
                ? $detalle->usuarios()->whereKey($rowId)->firstOrFail()
                : $detalle->usuarios()->firstOrNew(['user_id' => $request->user()->id]);
            $this->saveUserCount($detalle, $row, $row->exists ? $row->usuario_nombre : $request->user()->name, $data);
            $this->recalculateLine($detalle, Producto::find($detalle->producto_id));
            $this->refreshTotals($almacen);
        });

        return response()->json($detalle->fresh()->load(['conteos', 'usuarios.conteos']));
    }

    /** Quita el producto completo de la revisión, con los conteos de todos. */
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

    /** Quita sólo el conteo de un usuario; si era el único, el producto sale de la revisión. */
    public function destroyConteoUsuario(Request $request, Almacen $almacen, AlmacenDetalle $detalle, AlmacenDetalleUsuario $conteo)
    {
        $this->authorizeAction($request, 'Editar Almacenes');
        abort_unless($almacen->editable(), 422, 'Este almacén ya fue aplicado o anulado');
        abort_unless($detalle->almacen_id === $almacen->id && $conteo->almacen_detalle_id === $detalle->id, 404);

        DB::transaction(function () use ($almacen, $detalle, $conteo) {
            $conteo->delete();
            $detalle->usuarios()->exists()
                ? $this->recalculateLine($detalle, Producto::find($detalle->producto_id))
                : $detalle->delete();
            $this->refreshTotals($almacen);
        });

        return response()->json(['message' => "Conteo de {$conteo->usuario_nombre} quitado"]);
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

        $atRisk = DB::transaction(function () use ($request, $almacen) {
            foreach ($almacen->detalles as $detail) {
                $product = Producto::lockForUpdate()->find($detail->producto_id);
                abort_unless($product, 422, "El producto {$detail->nombre} ya no existe; quítelo de la revisión");

                $before = round((float) $product->stock_inicial, 3);
                $counted = round((float) $detail->cantidad, 3);
                $difference = round($counted - $before, 3);
                $countedLots = $detail->conteos;

                if ($countedLots->isNotEmpty()) {
                    // Se contaron los lotes uno por uno: eso es la verdad, se reemplazan los del sistema.
                    // Lo que otro usuario contó sin lote entra como un lote más, sin vencimiento.
                    $loose = round($counted - (float) $countedLots->sum(fn ($lot) => (float) $lot->cantidad), 3);
                    $this->replaceLots($detail, $product, $countedLots, max($loose, 0));
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

            // Todo lo contado con fecha de vencimiento entra solo a "Productos en riesgo".
            return ProductoRiesgo::registrarDesdeInventario($almacen->fresh(), $request->user());
        });

        return response()->json($this->withDetails($almacen->fresh())->setAttribute('riesgo_agregados', $atRisk));
    }

    /** Anula: si ya estaba aplicado deshace el ajuste de cada producto. */
    public function cancel(Request $request, Almacen $almacen)
    {
        $this->authorizeAction($request, 'Anular Almacenes');
        abort_if($almacen->estado === 'ANULADO', 422, 'El almacén ya está anulado');
        $autoriza = $this->authorizeWithPassword($request);

        DB::transaction(function () use ($request, $almacen, $autoriza) {
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

            $nota = 'ANULADO POR '.$request->user()->name.' · AUTORIZÓ '.$autoriza->name.' · '.now()->format('d/m/Y H:i');
            $almacen->update([
                'estado' => 'ANULADO',
                'observacion' => trim(($almacen->observacion ? $almacen->observacion."\n" : '').$nota),
            ]);
        });

        return response()->json($this->withDetails($almacen->fresh()));
    }

    public function destroy(Request $request, Almacen $almacen)
    {
        $this->authorizeAction($request, 'Anular Almacenes');
        abort_unless($almacen->editable(), 422, 'Sólo se puede eliminar un almacén en revisión');
        $this->authorizeWithPassword($request);
        $almacen->delete();

        return response()->json(['message' => 'Almacén eliminado']);
    }

    /**
     * Deja los lotes del producto tal cual se contaron: guarda el saldo de los lotes
     * vigentes (para poder reponerlos al anular), los pone en cero y crea uno por
     * cada lote contado. Los lotes viejos no se borran para no perder el historial
     * de ventas que los referencia.
     */
    private function replaceLots(AlmacenDetalle $detail, Producto $product, $countedLots, float $loose = 0): void
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
        if ($loose > 0.0001) {
            Lote::create([
                'producto_id' => $product->id,
                'almacen_detalle_id' => $detail->id,
                'cantidad_inicial' => $loose,
                'cantidad_disponible' => $loose,
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
            'usuario_conteo_id' => ['nullable', 'integer'],
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

    /** Datos del producto que se copian a la línea, para que el documento no cambie si luego se edita el producto. */
    private function productSnapshot(?Producto $product): array
    {
        return $product ? [
            'producto_id' => $product->id,
            'codigo' => $product->codigo,
            'nombre' => $product->nombre,
            'unidad' => $product->unidad,
            'foto' => $product->foto,
            'stock_sistema' => round((float) $product->stock_inicial, 3),
            'precio_compra' => $product->precio_compra,
        ] : [];
    }

    /**
     * Guarda el conteo de un usuario con sus lotes. Un lote/vencimiento enviado sin
     * `conteos` se toma como un único lote con toda la cantidad.
     */
    private function saveUserCount(AlmacenDetalle $detail, AlmacenDetalleUsuario $row, string $name, array $data): void
    {
        $lots = $data['conteos'] ?? [];
        if ($lots === [] && (($data['lote'] ?? null) || ($data['fecha_vencimiento'] ?? null)) && (float) ($data['cantidad'] ?? 0) > 0) {
            $lots = [['lote' => $data['lote'] ?? null, 'fecha_vencimiento' => $data['fecha_vencimiento'] ?? null, 'cantidad' => $data['cantidad']]];
        }

        $row->fill([
            'usuario_nombre' => $name,
            'cantidad' => $this->countedQuantity(['cantidad' => $data['cantidad'] ?? 0, 'conteos' => $lots]),
            'observacion' => $data['observacion'] ?? null,
        ])->save();

        $row->conteos()->delete();
        foreach ($lots as $lot) {
            $row->conteos()->create([
                'almacen_detalle_id' => $detail->id,
                'lote' => $lot['lote'] ?? null,
                'fecha_vencimiento' => $lot['fecha_vencimiento'] ?? null,
                'cantidad' => round((float) $lot['cantidad'], 3),
            ]);
        }
    }

    /**
     * La línea del producto es la suma de los conteos de todos los usuarios. Con un solo
     * lote en total se copia a la cabecera de la línea para mostrarlo sin abrir el detalle.
     */
    private function recalculateLine(AlmacenDetalle $detail, ?Producto $product): void
    {
        $rows = $detail->usuarios()->get();
        $lots = $detail->conteos()->get();
        $counted = round((float) $rows->sum(fn ($row) => (float) $row->cantidad), 3);
        $latest = $rows->sortByDesc('updated_at')->first();
        $snapshot = $this->productSnapshot($product);
        $price = (float) ($snapshot['precio_compra'] ?? $detail->precio_compra);

        $detail->update($snapshot + [
            'cantidad' => $counted,
            'user_id' => $latest?->user_id,
            'usuario_nombre' => mb_substr($rows->pluck('usuario_nombre')->unique()->implode(', '), 0, 255) ?: null,
            'lote' => $lots->count() === 1 ? $lots->first()->lote : null,
            'fecha_vencimiento' => $lots->count() === 1 ? $lots->first()->fecha_vencimiento : null,
            'total' => round($counted * $price, 2),
            'observacion' => mb_substr($rows->pluck('observacion')->filter()->implode(' · '), 0, 255) ?: null,
        ]);
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
    /**
     * Orden por columna de la tabla de avance, de menor a mayor o de mayor a menor (como en Excel).
     * `user_u{id}` / `user_n{nombre}` ordena por lo que contó esa persona; los vacíos van siempre al final.
     */
    private function sortDetails($query, string $system, string $column, string $direction): void
    {
        $expression = match ($column) {
            'producto' => 'almacen_detalles.nombre',
            'contado' => 'almacen_detalles.cantidad',
            'sistema' => $system,
            'diferencia' => "ROUND(almacen_detalles.cantidad - {$system}, 3)",
            'lote' => 'COALESCE((SELECT MIN(c.fecha_vencimiento) FROM almacen_detalle_conteos c WHERE c.almacen_detalle_id = almacen_detalles.id), almacen_detalles.fecha_vencimiento)',
            'resultado' => 'COALESCE(almacen_detalles.stock_nuevo, almacen_detalles.cantidad)',
            default => null,
        };
        $bindings = [];
        if ($expression === null && preg_match('/^user_u(\d+)$/', $column, $m)) {
            $expression = '(SELECT SUM(du.cantidad) FROM almacen_detalle_usuarios du WHERE du.almacen_detalle_id = almacen_detalles.id AND du.user_id = ?)';
            $bindings = [(int) $m[1]];
        } elseif ($expression === null && str_starts_with($column, 'user_n')) {
            $expression = '(SELECT SUM(du.cantidad) FROM almacen_detalle_usuarios du WHERE du.almacen_detalle_id = almacen_detalles.id AND du.user_id IS NULL AND du.usuario_nombre = ?)';
            $bindings = [substr($column, 6)];
        }
        if ($expression === null) {
            $query->orderBy('almacen_detalles.nombre')->orderBy('almacen_detalles.id');

            return;
        }

        $query->orderByRaw("({$expression}) IS NULL", $bindings)
            ->orderByRaw("{$expression} {$direction}", $bindings)
            ->orderBy('almacen_detalles.nombre')->orderBy('almacen_detalles.id');
    }

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

    /**
     * Anular o eliminar una revisión necesita la contraseña del usuario configurado en
     * config/inventario.php (el encargado), aunque quien lo pida tenga el permiso.
     * Tras 5 intentos fallidos se bloquea un minuto para no poder adivinarla.
     */
    private function authorizeWithPassword(Request $request): User
    {
        $request->validate(['password' => ['required', 'string']], ['password.required' => 'Ingrese la contraseña de autorización']);
        $key = 'inventario-anular:'.$request->user()->id;
        abort_if(RateLimiter::tooManyAttempts($key, 5), 429,
            'Demasiados intentos. Espere '.RateLimiter::availableIn($key).' segundos.');

        $autoriza = User::where('username', config('inventario.autoriza_anulacion'))->first();
        abort_unless($autoriza, 422, 'No existe el usuario que autoriza las anulaciones de inventario');
        if (! Hash::check((string) $request->input('password'), $autoriza->password)) {
            RateLimiter::hit($key, 60);
            abort(422, 'Contraseña de autorización incorrecta');
        }
        RateLimiter::clear($key);

        return $autoriza;
    }

    private function authorizeAction(Request $request, string $permission): void
    {
        abort_unless($request->user()?->hasPermissionTo($permission), 403, 'No tiene permiso para realizar esta acción');
    }
}
