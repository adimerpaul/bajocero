<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('venta_detalles', function (Blueprint $table) {
            $table->decimal('precio_base', 12, 4)->nullable()->after('precio_venta');
            $table->boolean('precio_cambiado')->default(false)->after('precio_base')->index();
        });
        DB::table('venta_detalles')->whereNull('precio_base')->update(['precio_base' => DB::raw('precio_venta')]);
    }

    public function down(): void
    {
        Schema::table('venta_detalles', function (Blueprint $table) {
            $table->dropIndex(['precio_cambiado']);
            $table->dropColumn(['precio_base', 'precio_cambiado']);
        });
    }
};
