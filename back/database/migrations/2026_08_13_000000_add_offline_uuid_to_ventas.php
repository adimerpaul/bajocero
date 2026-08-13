<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;

return new class extends Migration
{
    /** Aparece en el grupo "Ventas" de la pantalla de usuarios como "Crear Offline". */
    private string $permiso = 'Crear Ventas Offline';

    /**
     * Las ventas hechas sin conexión se guardan en el celular y se envían después.
     * El uuid lo genera el navegador al cobrar y es único en la tabla: si el envío
     * se repite (se cortó la respuesta, el cajero volvió a tocar "Enviar", dos
     * pestañas abiertas), la venta no se duplica.
     */
    public function up(): void
    {
        Schema::table('ventas', function (Blueprint $table) {
            $table->uuid('uuid')->nullable()->unique()->after('numero');
            $table->timestamp('fecha_offline')->nullable()->after('fecha');
        });

        Permission::firstOrCreate(['name' => $this->permiso, 'guard_name' => 'web']);
        User::where('username', 'admin')->first()?->givePermissionTo($this->permiso);
    }

    public function down(): void
    {
        Schema::table('ventas', function (Blueprint $table) {
            $table->dropUnique(['uuid']);
            $table->dropColumn(['uuid', 'fecha_offline']);
        });

        Permission::where('name', $this->permiso)->delete();
    }
};
