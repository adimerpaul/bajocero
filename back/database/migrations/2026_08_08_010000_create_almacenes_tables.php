<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;

return new class extends Migration
{
    private array $permisos = [
        'Ver Almacenes', 'Crear Almacenes', 'Editar Almacenes',
        'Aplicar Almacenes', 'Anular Almacenes',
    ];

    public function up(): void
    {
        // Revisión física del stock ("Inventario" en el menú): se arma como BORRADOR
        // entre varias personas y al aplicarla el stock del sistema pasa a ser lo contado.
        Schema::create('almacenes', function (Blueprint $table) {
            $table->id();
            $table->string('numero')->nullable()->index();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('usuario_nombre');
            $table->string('descripcion')->nullable();
            $table->string('estado', 20)->default('BORRADOR')->index();   // BORRADOR | APLICADO | ANULADO
            $table->text('observacion')->nullable();
            $table->decimal('total_cantidad', 14, 3)->default(0);
            $table->decimal('total_costo', 14, 2)->default(0);
            $table->timestamp('fecha')->index();
            $table->timestamp('fecha_aplicado')->nullable();
            $table->foreignId('aplicado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->string('aplicado_por_nombre')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        // Un producto aparece una sola vez por revisión: cada línea guarda el stock que
        // tenía el sistema al contarlo y, al aplicar, el valor anterior y el nuevo.
        Schema::create('almacen_detalles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('almacen_id')->constrained('almacenes')->cascadeOnDelete();
            $table->foreignId('producto_id')->nullable()->constrained('productos')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('usuario_nombre')->nullable();   // quién cargó esta línea
            $table->string('codigo', 50);
            $table->string('nombre');
            $table->string('unidad', 20);
            $table->decimal('stock_sistema', 12, 3)->default(0);
            $table->string('foto')->nullable();
            $table->string('lote')->nullable()->index();
            $table->date('fecha_vencimiento')->nullable()->index();
            $table->decimal('cantidad', 12, 3);
            $table->decimal('stock_anterior', 12, 3)->nullable();
            $table->decimal('stock_nuevo', 12, 3)->nullable();
            $table->decimal('diferencia', 12, 3)->nullable();
            $table->decimal('precio_compra', 12, 4)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->string('observacion')->nullable();
            $table->timestamps();
            $table->unique(['almacen_id', 'producto_id']);
        });

        // Un producto puede contarse en varios lotes: cada uno con su vencimiento y cantidad.
        // Cuando existen, `almacen_detalles.cantidad` es la suma de estas líneas.
        Schema::create('almacen_detalle_conteos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('almacen_detalle_id')->constrained('almacen_detalles')->cascadeOnDelete();
            $table->string('lote')->nullable();
            $table->date('fecha_vencimiento')->nullable();
            $table->decimal('cantidad', 12, 3);
            $table->timestamps();
        });

        // Lotes consumidos cuando lo contado es menor al sistema, para reponerlos al anular.
        Schema::create('almacen_detalle_lotes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('almacen_detalle_id')->constrained('almacen_detalles')->cascadeOnDelete();
            $table->foreignId('lote_id')->constrained('lotes')->cascadeOnDelete();
            $table->decimal('cantidad', 12, 3);
            $table->timestamps();
        });

        // Los lotes ya no vienen sólo de compras: ahora también de una revisión de inventario.
        Schema::table('lotes', function (Blueprint $table) {
            $table->foreignId('compra_detalle_id')->nullable()->change();
            $table->foreignId('almacen_detalle_id')->nullable()->after('compra_detalle_id')
                ->constrained('almacen_detalles')->nullOnDelete();
        });

        foreach ($this->permisos as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        User::where('username', 'admin')->first()?->givePermissionTo($this->permisos);
    }

    public function down(): void
    {
        Schema::table('lotes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('almacen_detalle_id');
        });

        Schema::dropIfExists('almacen_detalle_lotes');
        Schema::dropIfExists('almacen_detalle_conteos');
        Schema::dropIfExists('almacen_detalles');
        Schema::dropIfExists('almacenes');

        Permission::whereIn('name', $this->permisos)->delete();
    }
};
