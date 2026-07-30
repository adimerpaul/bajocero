<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('venta_detalles', function (Blueprint $table) {
            $table->boolean('descuenta_stock')->default(true)->after('cantidad');
        });
    }

    public function down(): void
    {
        Schema::table('venta_detalles', function (Blueprint $table) {
            $table->dropColumn('descuenta_stock');
        });
    }
};
