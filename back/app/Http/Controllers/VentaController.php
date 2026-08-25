<?php

namespace App\Http\Controllers;

use App\Exports\VentasExport;
use App\Exports\VentasHojaPreciosModificados;
use App\Models\Configuracion;
use App\Models\Lote;
use App\Models\Producto;
use App\Models\User;
use App\Models\Venta;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

class VentaController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizeAction($request, 'Ver Ventas');
        $query = $this->filteredQuery($request)
            ->with('detalles:id,venta_id,nombre,cantidad,unidad')
            ->withCount('detalles')
            ->latest('fecha');

        $perPage = (int) $request->input('per_page', 20);

        return response()->json($query->paginate($perPage === 0 ? 500 : min(max($perPage, 1), 500)));
    }

    public function summary(Request $request)
    {
        $this->authorizeAction($request, 'Ver Ventas');
        $query = $this->filteredQuery($request)->where('estado', 'COMPLETADA');

        return response()->json([
            'efectivo' => (clone $query)->sum('monto_efectivo'),
            'qr' => (clone $query)->sum('monto_qr'),
            'total' => (clone $query)->sum('total'),
            'descuento' => (clone $query)->sum('descuento'),
            'cantidad' => (clone $query)->count(),
            'usuarios' => User::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function dashboard(Request $request)
    {
        $this->authorizeAction($request, 'Ver Ventas');
        $sales = Venta::where('estado', 'COMPLETADA');
        $total = (float) (clone $sales)->sum('total');
        $count = (clone $sales)->count();
        $items = (float) DB::table('venta_detalles')
            ->join('ventas', 'ventas.id', '=', 'venta_detalles.venta_id')
            ->where('ventas.estado', 'COMPLETADA')
            ->whereNull('ventas.deleted_at')->whereNull('venta_detalles.deleted_at')
            ->sum('venta_detalles.cantidad');
        $profit = (float) DB::table('venta_detalles')
            ->join('ventas', 'ventas.id', '=', 'venta_detalles.venta_id')
            ->where('ventas.estado', 'COMPLETADA')
            ->whereNull('ventas.deleted_at')->whereNull('venta_detalles.deleted_at')
            ->selectRaw('COALESCE(SUM(((venta_detalles.precio_venta - venta_detalles.precio_compra) * venta_detalles.cantidad) - venta_detalles.descuento), 0) AS total')
            ->value('total');

        $dailyRaw = Venta::where('estado', 'COMPLETADA')->where('fecha', '>=', now()->subDays(6)->startOfDay())
            ->selectRaw('DATE(fecha) as dia, SUM(total) as total')->groupBy('dia')->pluck('total', 'dia');
        $daily = collect(range(6, 0))->map(function ($days) use ($dailyRaw) {
            $date = now()->subDays($days);

            return ['label' => $date->format('d/m'), 'total' => (float) ($dailyRaw[$date->toDateString()] ?? 0)];
        });

        $byUser = Venta::where('estado', 'COMPLETADA')->selectRaw('usuario_nombre as nombre, SUM(total) as total')
            ->groupBy('usuario_nombre')->orderByDesc('total')->limit(8)->get();
        $payments = Venta::where('estado', 'COMPLETADA')->selectRaw('tipo_pago as nombre, SUM(total) as total')
            ->groupBy('tipo_pago')->get();
        $topProducts = DB::table('venta_detalles')->join('ventas', 'ventas.id', '=', 'venta_detalles.venta_id')
            ->where('ventas.estado', 'COMPLETADA')->whereNull('ventas.deleted_at')->whereNull('venta_detalles.deleted_at')
            ->selectRaw('venta_detalles.producto_id, venta_detalles.nombre, venta_detalles.foto, SUM(venta_detalles.cantidad) as cantidad, SUM(venta_detalles.total) as total')
            ->groupBy('venta_detalles.producto_id', 'venta_detalles.nombre', 'venta_detalles.foto')
            ->orderByDesc('cantidad')->limit(8)->get();

        return response()->json([
            'indicadores' => ['ventas' => $total, 'ganancia' => $profit, 'productos' => $items, 'cantidad_ventas' => $count, 'ticket_promedio' => $count ? $total / $count : 0],
            'diario' => $daily, 'usuarios' => $byUser, 'pagos' => $payments, 'productos_top' => $topProducts,
        ]);
    }

    public function exportExcel(Request $request)
    {
        $this->authorizeAction($request, 'Ver Ventas');
        $ventas = $this->filteredQuery($request)->with('detalles')->withCount('detalles')->latest('fecha')->get();
        $productos = DB::table('venta_detalles')
            ->whereNull('venta_detalles.deleted_at')
            ->whereIn('venta_id', $this->filteredQuery($request)->where('estado', 'COMPLETADA')->select('id'))
            ->selectRaw('nombre, unidad, SUM(cantidad) as cantidad, SUM(total) as total')
            ->groupBy('nombre', 'unidad')->orderByDesc('total')->limit(15)->get();

        return Excel::download(
            new VentasExport($ventas, $productos, $this->reportMeta($request)),
            'ventas_'.now()->format('Ymd_His').'.xlsx'
        );
    }

    /** Encabezado del reporte: empresa, período, filtros aplicados y quién exportó. */
    private function reportMeta(Request $request): array
    {
        $config = Configuracion::first();
        $from = $request->date('desde');
        $to = $request->date('hasta');
        $fromTime = substr($this->normalizeTime($request->input('hora_desde'), '00:00:00') ?? '00:00', 0, 5);
        $toTime = substr($this->normalizeTime($request->input('hora_hasta'), '23:59:59') ?? '23:59', 0, 5);

        $period = 'Todos los registros';
        if ($from || $to) {
            $period = ($from ? $from->format('d/m/Y') : 'inicio').' '.$fromTime.'  al  '.($to ? $to->format('d/m/Y') : 'hoy').' '.$toTime;
        }

        $filters = [];
        if ($userId = $request->integer('user_id')) {
            $filters[] = 'Usuario: '.(User::find($userId)?->name ?? $userId);
        }
        if ($search = trim((string) $request->input('q'))) {
            $filters[] = 'Búsqueda: "'.$search.'"';
        }

        $user = $request->user();

        return [
            'empresa' => $config?->nombre_empresa ?: 'Bajo Cero',
            'nit' => $config?->nit,
            'direccion' => $config?->direccion,
            'telefono' => $config?->telefono,
            'periodo' => $period,
            'filtros' => $filters ? implode('   ·   ', $filters) : 'Sin filtros adicionales',
            'exportado_por' => trim(($user?->name ?? '-').($user?->username ? ' ('.$user->username.')' : '')),
            'exportado_en' => now()->format('d/m/Y H:i'),
        ];
    }

    public function exportPdf(Request $request)
    {
        $this->authorizeAction($request, 'Ver Ventas');
        $ventas = $this->filteredQuery($request)->latest('fecha')->get();
        $valid = $ventas->where('estado', 'COMPLETADA');
        $resumen = ['efectivo' => $valid->sum('monto_efectivo'), 'qr' => $valid->sum('monto_qr'), 'total' => $valid->sum('total')];

        return Pdf::loadView('ventas.reporte', compact('ventas', 'resumen'))->setPaper('letter', 'landscape')
            ->download('ventas_'.now()->format('Ymd_His').'.pdf');
    }

    public function exportChangedPricesExcel(Request $request)
    {
        $this->authorizeAction($request, 'Ver Ventas');
        $ventas = $this->changedPriceSales($request);

        return Excel::download(
            new VentasHojaPreciosModificados($ventas, $this->reportMeta($request)),
            'precios_modificados_'.now()->format('Ymd_His').'.xlsx'
        );
    }

    public function exportChangedPricesPdf(Request $request)
    {
        $this->authorizeAction($request, 'Ver Ventas');
        $ventas = $this->changedPriceSales($request);
        $detalles = $ventas->flatMap(fn ($venta) => $venta->detalles->map(fn ($detalle) => [
            'venta' => $venta,
            'detalle' => $detalle,
            'diferencia' => (float) $detalle->precio_venta - (float) $detalle->precio_base,
        ]));

        return Pdf::loadView('ventas.precios_modificados', [
            'detalles' => $detalles,
            'meta' => $this->reportMeta($request),
        ])->setPaper('letter', 'landscape')->download('precios_modificados_'.now()->format('Ymd_His').'.pdf');
    }

    private function changedPriceSales(Request $request)
    {
        return $this->filteredQuery($request)
            ->whereHas('detalles', fn ($query) => $query->where('precio_cambiado', true))
            ->with(['detalles' => fn ($query) => $query->where('precio_cambiado', true)])
            ->latest('fecha')->get();
    }

    public function show(Request $request, Venta $venta)
    {
        $this->authorizeAction($request, 'Ver Ventas');

        return response()->json($venta->load('detalles'));
    }

    /**
     * Ventas hechas sin conexión: dice cuáles de esos uuid ya están registrados.
     * El celular lo consulta antes de enviar la cola para no repetir una venta que
     * sí llegó al servidor aunque la respuesta se haya perdido.
     */
    public function verificarOffline(Request $request)
    {
        $this->authorizeAction($request, ['Crear Ventas', 'Crear Ventas Offline']);
        $data = $request->validate([
            'uuids' => ['required', 'array', 'min:1', 'max:200'],
            'uuids.*' => ['required', 'uuid'],
        ]);

        $ventas = Venta::whereIn('uuid', $data['uuids'])
            ->get(['id', 'uuid', 'numero', 'fecha', 'total', 'estado']);

        return response()->json(['registradas' => $ventas->keyBy('uuid')]);
    }

    public function store(Request $request)
    {
        // Con uuid es el envío de una venta hecha sin conexión: basta el permiso offline.
        $this->authorizeAction($request, $request->filled('uuid')
            ? ['Crear Ventas', 'Crear Ventas Offline']
            : 'Crear Ventas');
        $data = $request->validate([
            'uuid' => ['nullable', 'uuid'],
            'fecha_offline' => ['nullable', 'date'],
            'descuento' => ['nullable', 'numeric', 'min:0'],
            'tipo_pago' => ['required', 'in:EFECTIVO,QR,COMBINADO'],
            'monto_efectivo' => ['nullable', 'numeric', 'min:0'],
            'monto_qr' => ['nullable', 'numeric', 'min:0'],
            'observacion' => ['nullable', 'string', 'max:1000'],
            'detalles' => ['required', 'array', 'min:1'],
            'detalles.*.producto_id' => ['required', 'integer', 'exists:productos,id'],
            'detalles.*.cantidad' => ['required', 'numeric', 'min:0.001', 'decimal:0,3'],
            'detalles.*.precio_venta' => ['required', 'numeric', 'min:0'],
        ]);

        // Reenvío de una venta offline que ya llegó: se devuelve la registrada, no se duplica.
        if ($registrada = $this->ventaOfflineRegistrada($data['uuid'] ?? null)) {
            return response()->json($registrada->load('detalles')->toArray() + ['duplicada' => true]);
        }

        try {
            $venta = $this->registrarVenta($request, $data);
        } catch (UniqueConstraintViolationException $e) {
            // Dos envíos simultáneos del mismo uuid: gana el primero.
            $registrada = $this->ventaOfflineRegistrada($data['uuid'] ?? null);
            if (! $registrada) {
                throw $e;
            }

            return response()->json($registrada->load('detalles')->toArray() + ['duplicada' => true]);
        }

        return response()->json($venta->load('detalles'), 201);
    }

    private function ventaOfflineRegistrada(?string $uuid): ?Venta
    {
        return $uuid ? Venta::where('uuid', $uuid)->first() : null;
    }

    /**
     * Fecha real del cobro para una venta offline. Se ignora un reloj del celular
     * adelantado o atrasado más de 30 días para no ensuciar los reportes.
     */
    private function fechaOffline(?string $fecha): ?Carbon
    {
        if (! $fecha) {
            return null;
        }
        $moment = Carbon::parse($fecha);

        return $moment->isFuture() || $moment->lt(now()->subDays(30)) ? null : $moment;
    }

    private function registrarVenta(Request $request, array $data): Venta
    {
        return DB::transaction(function () use ($request, $data) {
            $items = [];
            $subtotal = 0;
            foreach ($data['detalles'] as $detail) {
                $product = Producto::lockForUpdate()->findOrFail($detail['producto_id']);
                $quantity = round((float) $detail['cantidad'], 3);
                $deductStock = (float) $product->stock_inicial > 0;
                abort_if($deductStock && (float) $product->stock_inicial + 0.0001 < $quantity, 422, "Stock insuficiente para {$product->nombre}");
                $salePrice = round((float) $detail['precio_venta'], 4);
                $basePrice = round((float) $product->precio_venta, 4);
                $priceChanged = abs($salePrice - $basePrice) > 0.00005;
                $lineSubtotal = round($salePrice * $quantity, 2);
                $subtotal += $lineSubtotal;
                $items[] = [$product, $quantity, $salePrice, $basePrice, $priceChanged, $lineSubtotal, $deductStock];
            }

            $discount = round((float) ($data['descuento'] ?? 0), 2);
            abort_if($discount > $subtotal, 422, 'El descuento no puede superar el subtotal');
            $total = round($subtotal - $discount, 2);
            $cash = $data['tipo_pago'] === 'EFECTIVO' ? $total : round((float) ($data['monto_efectivo'] ?? 0), 2);
            $qr = $data['tipo_pago'] === 'QR' ? $total : round((float) ($data['monto_qr'] ?? 0), 2);
            abort_if(abs(($cash + $qr) - $total) > 0.009, 422, 'Los montos de efectivo y QR deben sumar el total de la venta');

            $offlineDate = $this->fechaOffline($data['fecha_offline'] ?? null);
            $sale = Venta::create([
                'uuid' => $data['uuid'] ?? null,
                'user_id' => $request->user()->id,
                'usuario_nombre' => $request->user()->name,
                'subtotal' => $subtotal,
                'descuento' => $discount,
                'total' => $total,
                'tipo_pago' => $data['tipo_pago'],
                'monto_efectivo' => $cash,
                'monto_qr' => $qr,
                'estado' => 'COMPLETADA',
                'observacion' => $data['observacion'] ?? null,
                'fecha' => $offlineDate ?? now(),
                'fecha_offline' => $offlineDate,
            ]);
            $sale->update(['numero' => 'V-'.str_pad((string) $sale->id, 8, '0', STR_PAD_LEFT)]);

            $allocated = 0;
            foreach ($items as $index => [$product, $quantity, $salePrice, $basePrice, $priceChanged, $lineSubtotal, $deductStock]) {
                $lineDiscount = $index === array_key_last($items)
                    ? $discount - $allocated
                    : round($discount * ($lineSubtotal / $subtotal), 2);
                $allocated += $lineDiscount;
                $saleDetail = $sale->detalles()->create([
                    'producto_id' => $product->id,
                    'codigo' => $product->codigo,
                    'codigo_barras' => $product->codigo_barras,
                    'nombre' => $product->nombre,
                    'categoria' => $product->categoria,
                    'unidad' => $product->unidad,
                    'foto' => $product->foto,
                    'precio_compra' => $product->precio_compra,
                    'precio_venta' => $salePrice,
                    'precio_base' => $basePrice,
                    'precio_cambiado' => $priceChanged,
                    'cantidad' => $quantity,
                    'descuenta_stock' => $deductStock,
                    'subtotal' => $lineSubtotal,
                    'descuento' => $lineDiscount,
                    'total' => $lineSubtotal - $lineDiscount,
                ]);
                if (! $deductStock) {
                    continue;
                }
                $product->decrement('stock_inicial', $quantity);
                $remaining = $quantity;
                $lots = Lote::where('producto_id', $product->id)
                    ->where('cantidad_disponible', '>', 0)
                    ->orderByRaw('fecha_vencimiento IS NULL')
                    ->orderBy('fecha_vencimiento')->orderBy('id')->lockForUpdate()->get();
                foreach ($lots as $lot) {
                    if ($remaining <= 0.0001) {
                        break;
                    }
                    $taken = min($remaining, (float) $lot->cantidad_disponible);
                    $lot->decrement('cantidad_disponible', $taken);
                    DB::table('venta_detalle_lotes')->insert([
                        'venta_detalle_id' => $saleDetail->id, 'lote_id' => $lot->id,
                        'cantidad' => $taken, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                    $remaining = round($remaining - $taken, 3);
                }
            }

            return $sale;
        });
    }

    private function filteredQuery(Request $request)
    {
        $query = Venta::query();
        if ($search = trim((string) $request->input('q'))) {
            $query->where(fn ($q) => $q->where('numero', 'like', "%{$search}%")
                ->orWhere('usuario_nombre', 'like', "%{$search}%")
                ->orWhere('estado', 'like', "%{$search}%"));
        }
        if ($from = $request->date('desde')) {
            $query->whereDate('fecha', '>=', $from);
        }
        if ($to = $request->date('hasta')) {
            $query->whereDate('fecha', '<=', $to);
        }
        if ($fromTime = $this->normalizeTime($request->input('hora_desde'), '00:00:00')) {
            $query->whereTime('fecha', '>=', $fromTime);
        }
        if ($toTime = $this->normalizeTime($request->input('hora_hasta'), '23:59:59')) {
            $query->whereTime('fecha', '<=', $toTime);
        }
        if ($userId = $request->integer('user_id')) {
            $query->where('user_id', $userId);
        }

        return $query;
    }

    /**
     * Convierte "08:30" en "08:30:00" / "08:30:59" según sea el inicio o el fin del rango.
     * Sin los segundos, un "hasta las 23:59" dejaría fuera las ventas de 23:59:01 en adelante.
     */
    private function normalizeTime(?string $time, string $fallbackSeconds): ?string
    {
        $time = trim((string) $time);
        if (! preg_match('/^([01]\d|2[0-3]):([0-5]\d)(:([0-5]\d))?$/', $time, $parts)) {
            return null;
        }

        return isset($parts[4])
            ? $time
            : $parts[1].':'.$parts[2].':'.substr($fallbackSeconds, -2);
    }

    public function cancel(Request $request, Venta $venta)
    {
        $this->authorizeAction($request, 'Anular Ventas');
        abort_if($venta->estado === 'ANULADA', 422, 'La venta ya está anulada');

        DB::transaction(function () use ($venta) {
            foreach ($venta->detalles as $detail) {
                if (! $detail->descuenta_stock) {
                    continue;
                }
                Producto::whereKey($detail->producto_id)->increment('stock_inicial', $detail->cantidad);
                $allocations = DB::table('venta_detalle_lotes')->where('venta_detalle_id', $detail->id)->get();
                foreach ($allocations as $allocation) {
                    Lote::whereKey($allocation->lote_id)->increment('cantidad_disponible', $allocation->cantidad);
                }
            }
            $venta->update(['estado' => 'ANULADA']);
        });

        return response()->json($venta->fresh());
    }

    /** @param  string|string[]  $permission  Con varios, alcanza con tener uno. */
    private function authorizeAction(Request $request, string|array $permission): void
    {
        $user = $request->user();
        $allowed = collect((array) $permission)->contains(fn ($name) => (bool) $user?->hasPermissionTo($name));
        abort_unless($allowed, 403, 'No tiene permiso para realizar esta acción');
    }
}
