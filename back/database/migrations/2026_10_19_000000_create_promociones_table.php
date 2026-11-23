<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;

return new class extends Migration
{
    private array $permisos = ['Ver Promociones', 'Crear Promociones', 'Editar Promociones', 'Eliminar Promociones'];

    public function up(): void
    {
        // Sorteos por monto de compra: si la venta llega al monto se imprime un cupón
        // para que el cliente anote su nombre y celular y lo deposite en el ánfora.
        Schema::create('promociones', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 120);
            $table->string('titulo', 120);
            $table->string('subtitulo', 255)->nullable();
            $table->decimal('monto', 12, 2);
            $table->boolean('estado')->default(true)->index();
            $table->timestamps();
            $table->softDeletes();
        });

        foreach ($this->permisos as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        User::where('username', 'admin')->first()?->givePermissionTo($this->permisos);
    }

    public function down(): void
    {
        Schema::dropIfExists('promociones');

        Permission::whereIn('name', $this->permisos)->delete();
    }
};
