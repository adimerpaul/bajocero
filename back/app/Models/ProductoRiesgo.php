<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductoRiesgo extends Model
{
    protected $table = 'productos_riesgo';

    protected $fillable = [
        'producto_id', 'codigo', 'nombre', 'unidad', 'foto', 'lote', 'fecha_vencimiento', 'cantidad',
        'origen', 'almacen_id', 'documento', 'observacion', 'estado',
        'agregado_por', 'agregado_por_nombre', 'agregado_at',
        'quitado_por', 'quitado_por_nombre', 'quitado_at', 'motivo_quitado',
    ];

    protected $casts = [
        'fecha_vencimiento' => 'date:Y-m-d',
        'cantidad' => 'decimal:3',
        'agregado_at' => 'datetime',
        'quitado_at' => 'datetime',
    ];

    public function producto()
    {
        return $this->belongsTo(Producto::class)->withTrashed();
    }

    /** Busca el registro ACTIVO del mismo producto + lote + vencimiento (no puede haber dos). */
    public static function activoPara(int $productId, ?string $lot, ?string $expiry): ?self
    {
        return static::where('estado', 'ACTIVO')->where('producto_id', $productId)
            ->when($lot === null, fn ($q) => $q->whereNull('lote'), fn ($q) => $q->where('lote', $lot))
            ->when($expiry === null, fn ($q) => $q->whereNull('fecha_vencimiento'), fn ($q) => $q->whereDate('fecha_vencimiento', $expiry))
            ->first();
    }

    /**
     * Al aplicar un inventario la lista se reemplaza: todos los activos pasan a QUITADO (quedan en el
     * historial con quién y cuándo) y entra cada lote contado (o línea sin lotes) que tenga fecha de
     * vencimiento. No modifica nada del inventario ni del stock.
     */
    public static function registrarDesdeInventario(Almacen $almacen, User $user): int
    {
        static::where('estado', 'ACTIVO')->update([
            'estado' => 'QUITADO',
            'quitado_por' => $user->id, 'quitado_por_nombre' => $user->name, 'quitado_at' => now(),
            'motivo_quitado' => 'REEMPLAZADO POR EL INVENTARIO '.($almacen->numero ?: '#'.$almacen->id),
            'updated_at' => now(),
        ]);

        $created = 0;
        $almacen->loadMissing('detalles.conteos', 'detalles.producto');
        foreach ($almacen->detalles as $detail) {
            $sources = $detail->conteos->isNotEmpty() ? $detail->conteos : collect([$detail]);
            foreach ($sources as $source) {
                if (! $detail->producto_id || ! $source->fecha_vencimiento || (float) $source->cantidad <= 0) {
                    continue;
                }
                $lot = trim((string) $source->lote) !== '' ? mb_strtoupper(trim($source->lote)) : null;
                $expiry = $source->fecha_vencimiento->format('Y-m-d');
                if (static::activoPara($detail->producto_id, $lot, $expiry)) {
                    continue;
                }
                static::create([
                    'producto_id' => $detail->producto_id, 'codigo' => $detail->codigo, 'nombre' => $detail->nombre,
                    'unidad' => $detail->unidad, 'foto' => $detail->producto?->foto ?: $detail->foto,
                    'lote' => $lot, 'fecha_vencimiento' => $expiry, 'cantidad' => round((float) $source->cantidad, 3),
                    'origen' => 'INVENTARIO', 'almacen_id' => $almacen->id, 'documento' => $almacen->numero,
                    'estado' => 'ACTIVO',
                    'agregado_por' => $user->id, 'agregado_por_nombre' => $user->name, 'agregado_at' => now(),
                ]);
                $created++;
            }
        }

        return $created;
    }
}
