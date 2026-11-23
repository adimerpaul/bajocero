<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;

/**
 * Productos en riesgo: una lista de seguimiento (no mueve stock ni toca inventario).
 *
 * Cada fila es un registro con su propio historial: quién lo agregó y cuándo, y quién lo
 * quitó y cuándo. Quitar no borra la fila; si se vuelve a agregar se crea una fila nueva.
 */
return new class extends Migration
{
    private array $permisos = ['Ver Productos en Riesgo', 'Gestionar Productos en Riesgo'];

    public function up(): void
    {
        Schema::create('productos_riesgo', function (Blueprint $table) {
            $table->id();
            $table->foreignId('producto_id')->nullable()->constrained('productos')->nullOnDelete();
            // Copia del producto al momento de agregarlo, como en compras y ventas.
            $table->string('codigo', 50)->nullable();
            $table->string('nombre');
            $table->string('unidad', 20)->nullable();
            $table->string('foto')->nullable();
            $table->string('lote')->nullable();
            $table->date('fecha_vencimiento')->nullable()->index();
            $table->decimal('cantidad', 12, 3)->nullable();
            $table->string('origen', 20)->default('MANUAL');   // MANUAL | POR_VENCER | VENCIDO
            $table->text('observacion')->nullable();
            $table->string('estado', 20)->default('ACTIVO')->index();   // ACTIVO | QUITADO
            $table->foreignId('agregado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->string('agregado_por_nombre');
            $table->timestamp('agregado_at')->index();
            $table->foreignId('quitado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->string('quitado_por_nombre')->nullable();
            $table->timestamp('quitado_at')->nullable()->index();
            $table->text('motivo_quitado')->nullable();
            $table->timestamps();
        });

        foreach ($this->permisos as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        User::where('username', 'admin')->first()?->givePermissionTo($this->permisos);
    }

    public function down(): void
    {
        Schema::dropIfExists('productos_riesgo');
        Permission::whereIn('name', $this->permisos)->delete();
    }
};
