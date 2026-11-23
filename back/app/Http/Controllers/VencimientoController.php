<?php

namespace App\Http\Controllers;

use App\Exports\VencimientosExport;
use App\Models\Almacen;
use App\Models\AlmacenDetalle;
use App\Models\AlmacenDetalleConteo;
use App\Models\ProductoRiesgo;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Productos por vencer / vencidos según el último inventario APLICADO (ya no según las compras).
 *
 * Cada línea contada con lotes aporta un renglón por lote (`almacen_detalle_conteos`); una línea
 * sin lotes aporta su propio vencimiento si lo tiene. Un renglón "quitado" no se borra: se marca
 * con `vencimiento_oculto_at`, va al final de la lista y se puede volver a habilitar.
 */
class VencimientoController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizeView($request);
        [$inventory, $rows] = $this->rows($request);

        return response()->json([
            'inventario' => $inventory?->only(['id', 'numero', 'descripcion', 'fecha_aplicado', 'aplicado_por_nombre']),
            'data' => $rows->values(),
        ]);
    }

    public function exportExcel(Request $request)
    {
        $this->authorizeView($request);
        [, $rows] = $this->rows($request);
        $name = ($request->input('estado') === 'vencido' ? 'vencidos_' : 'por_vencer_').now()->format('Ymd_His').'.xlsx';

        return Excel::download(new VencimientosExport($rows), $name);
    }

    /** Quita (manda al final) o vuelve a habilitar un renglón. `tipo` es `lote` (conteo) o `linea` (detalle sin lotes). */
    public function toggle(Request $request, string $tipo, int $id)
    {
        $this->authorizeView($request);
        $data = $request->validate(['oculto' => ['required', 'boolean']]);
        $model = match ($tipo) {
            'lote' => AlmacenDetalleConteo::findOrFail($id),
            'linea' => AlmacenDetalle::findOrFail($id),
            default => abort(404),
        };
        $model->forceFill($data['oculto']
            ? ['vencimiento_oculto_at' => now(), 'vencimiento_oculto_por' => $request->user()->name]
            : ['vencimiento_oculto_at' => null, 'vencimiento_oculto_por' => null]
        )->save();

        return response()->json(['oculto' => $data['oculto']]);
    }

    /** @return array{0: ?Almacen, 1: Collection} */
    private function rows(Request $request): array
    {
        $inventory = Almacen::where('estado', 'APLICADO')->orderByDesc('fecha_aplicado')->orderByDesc('id')->first();
        if (! $inventory) {
            return [null, collect()];
        }

        $expired = $request->input('estado') === 'vencido';
        $days = min(max((int) $request->input('dias', 30), 1), 3650);
        $search = mb_strtoupper(trim((string) $request->input('q', '')));
        $visibility = $request->input('mostrar', 'todos');   // todos | activos | quitados
        $today = today();

        $details = AlmacenDetalle::with(['conteos', 'producto:id,foto,categoria,codigo_barras'])
            ->where('almacen_id', $inventory->id)->get();

        $rows = collect();
        foreach ($details as $detail) {
            $base = [
                'producto_id' => $detail->producto_id,
                'codigo' => $detail->codigo,
                'codigo_barras' => $detail->producto?->codigo_barras,
                'nombre' => $detail->nombre,
                'unidad' => $detail->unidad,
                'categoria' => $detail->producto?->categoria,
                'foto' => $detail->producto?->foto ?: $detail->foto,
                'inventario' => $inventory->numero,
            ];
            $sources = $detail->conteos->isNotEmpty()
                ? $detail->conteos->map(fn ($c) => ['tipo' => 'lote', 'model' => $c])
                : collect([['tipo' => 'linea', 'model' => $detail]]);
            foreach ($sources as $source) {
                $item = $source['model'];
                if (! $item->fecha_vencimiento || (float) $item->cantidad <= 0) {
                    continue;
                }
                $date = $item->fecha_vencimiento instanceof \DateTimeInterface
                    ? $item->fecha_vencimiento->format('Y-m-d') : substr((string) $item->fecha_vencimiento, 0, 10);
                $rows->push($base + [
                    'key' => $source['tipo'].'-'.$item->id,
                    'tipo' => $source['tipo'],
                    'id' => $item->id,
                    'lote' => $item->lote,
                    'cantidad' => (float) $item->cantidad,
                    'fecha_vencimiento' => $date,
                    'dias' => (int) $today->diffInDays($date, false),
                    'oculto' => $item->vencimiento_oculto_at !== null,
                    'oculto_por' => $item->vencimiento_oculto_por,
                    'oculto_at' => $item->vencimiento_oculto_at ? (string) $item->vencimiento_oculto_at : null,
                ]);
            }
        }

        $rows = $rows->filter(function ($row) use ($expired, $days, $search, $visibility) {
            if ($expired ? $row['dias'] >= 0 : ($row['dias'] < 0 || $row['dias'] > $days)) {
                return false;
            }
            if ($visibility === 'activos' && $row['oculto']) {
                return false;
            }
            if ($visibility === 'quitados' && ! $row['oculto']) {
                return false;
            }

            return $search === '' || collect([$row['codigo'], $row['codigo_barras'], $row['nombre'], $row['lote'], $row['categoria']])
                ->contains(fn ($value) => $value !== null && str_contains(mb_strtoupper((string) $value), $search));
        });

        // Marca los que ya están en "Productos en riesgo" (activos) por producto + lote + vencimiento.
        $atRisk = ProductoRiesgo::where('estado', 'ACTIVO')->whereIn('producto_id', $rows->pluck('producto_id')->filter()->unique())
            ->get()->keyBy(fn ($r) => $r->producto_id.'|'.mb_strtoupper((string) $r->lote).'|'.$r->fecha_vencimiento?->format('Y-m-d'));
        $rows = $rows->map(function ($row) use ($atRisk) {
            $risk = $atRisk->get($row['producto_id'].'|'.mb_strtoupper((string) $row['lote']).'|'.$row['fecha_vencimiento']);
            $row['riesgo_id'] = $risk?->id;
            $row['riesgo_por'] = $risk?->agregado_por_nombre;

            return $row;
        });

        // Activos primero (los que vencen antes arriba); los quitados al final.
        return [$inventory, $rows->sortBy([['oculto', 'asc'], ['fecha_vencimiento', 'asc'], ['nombre', 'asc']])->values()];
    }

    private function authorizeView(Request $request): void
    {
        abort_unless($request->user()?->hasAnyPermission(['Ver Compras', 'Ver Almacenes']), 403, 'No tiene permiso para realizar esta acción');
    }
}
