<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Seguimiento del correo de la factura. El PDF no se guarda: se genera en memoria
 * cada vez que se envía o se descarga, para no llenar el disco del servidor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ventas', function (Blueprint $table) {
            $table->timestamp('email_enviado_en')->nullable()->after('siat_evento_id');
            $table->text('email_error')->nullable()->after('email_enviado_en');
        });
    }

    public function down(): void
    {
        Schema::table('ventas', fn (Blueprint $table) => $table->dropColumn(['email_enviado_en', 'email_error']));
    }
};
