<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Facturación computarizada en línea (SIAT, modalidad 2).
 *
 * - clientes: padrón de compradores, uno por tipo + número + complemento de documento.
 * - siat_tokens / siat_cuis / siat_cufds: credenciales del SIN. El CUIS dura un año y
 *   el CUFD un día; se guarda el historial porque una factura fuera de línea se envía
 *   después con el CUFD vigente al momento en que se emitió.
 * - siat_eventos_significativos: contingencias con las que se envían en paquete las
 *   facturas emitidas sin conexión con Impuestos.
 * - siat_catalogos: copia de las paramétricas sincronizadas (actividades, productos
 *   SIN, leyendas, unidades, etc.).
 * - categorias.codigo_producto_sin: con qué producto SIN se declara cada categoría.
 */
return new class extends Migration
{
    private array $permisos = [
        'Ver Clientes', 'Crear Clientes', 'Editar Clientes', 'Eliminar Clientes', 'Gestionar Impuestos',
    ];

    /** Categorías del catálogo → producto SIN de la actividad 4711100 (supermercados). */
    private array $codigosSin = [
        'POLLO' => 1004672, 'CERDO' => 1004673, 'RES' => 1004667, 'PESCADOS' => 1000542,
        'SOFIA' => 1005538, 'TORITO' => 1005538,
        'LECHES' => 1004685, 'LACTEOS' => 1004685, 'QUESOS' => 1004685, 'YOGURES Y KÉFIR' => 1004685,
        'MANTEQUILLAS Y CREMAS' => 1004685, 'HELADOS' => 1003816, 'JUGOS Y NÉCTARES' => 1004684,
        'AGUAS Y GASEOSAS' => 1004687, 'GASEOSAS' => 1004687, 'LICORES' => 1004686, 'VINOS' => 1004686,
        'GALLETAS' => 1000612, 'GALLETAS Y CEREALES' => 1000612, 'PANES' => 1000612, 'CEREALES' => 1000587,
        'CONDIMENTOS' => 1004692, 'MATES' => 1004691, 'HOGAR' => 1004683, 'PIPOCAS' => 1004689,
    ];

    public function up(): void
    {
        Schema::create('clientes', function (Blueprint $table) {
            $table->id();
            // CI, CEX, PAS, OD, NIT (paramétrica tipo de documento de identidad del SIN).
            $table->string('tipo_documento', 5)->default('CI');
            $table->string('numero_documento', 20);
            $table->string('complemento', 5)->default('');
            $table->string('nombre', 500);
            $table->string('email')->nullable();
            $table->string('telefono', 80)->nullable();
            $table->string('direccion')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tipo_documento', 'numero_documento', 'complemento']);
            $table->index('nombre');
        });

        Schema::create('siat_tokens', function (Blueprint $table) {
            $table->id();
            $table->longText('token_cifrado');
            $table->dateTime('vence_en')->index();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('siat_cuis', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 100);
            $table->dateTime('vence_en')->index();
            $table->unsignedSmallInteger('sucursal')->default(0);
            $table->unsignedSmallInteger('punto_venta')->default(0);
            $table->timestamps();
        });

        Schema::create('siat_cufds', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 150)->index();
            $table->string('codigo_control', 50);
            $table->string('direccion', 500)->nullable();
            $table->dateTime('vence_en')->index();
            $table->unsignedSmallInteger('sucursal')->default(0);
            $table->unsignedSmallInteger('punto_venta')->default(0);
            $table->timestamps();
        });

        Schema::create('siat_eventos_significativos', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('codigo_motivo');
            $table->string('descripcion', 500);
            $table->dateTime('inicio');
            $table->dateTime('fin');
            $table->string('cufd', 150);
            $table->string('cufd_evento', 150);
            $table->string('codigo_evento')->nullable();
            $table->string('codigo_recepcion')->nullable();
            // REGISTRANDO, REGISTRADO, EN_VALIDACION, VALIDADO, OBSERVADO, ERROR
            $table->string('estado', 30)->default('REGISTRANDO')->index();
            $table->text('mensaje')->nullable();
            $table->unsignedInteger('cantidad_facturas')->default(0);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('siat_catalogos', function (Blueprint $table) {
            $table->id();
            // ACTIVIDAD, PRODUCTO, LEYENDA, UNIDAD_MEDIDA, DOCUMENTO_IDENTIDAD, EVENTO, MOTIVO_ANULACION, METODO_PAGO
            $table->string('tipo', 30)->index();
            $table->string('codigo', 30)->nullable();
            $table->string('codigo_actividad', 20)->nullable();
            $table->text('descripcion');
            $table->timestamps();
        });

        Schema::table('categorias', function (Blueprint $table) {
            $table->string('actividad_economica', 20)->nullable()->after('color');
            $table->unsignedInteger('codigo_producto_sin')->nullable()->after('actividad_economica');
        });
        foreach ($this->codigosSin as $nombre => $codigo) {
            DB::table('categorias')->where('nombre', $nombre)
                ->update(['actividad_economica' => '4711100', 'codigo_producto_sin' => $codigo]);
        }

        Schema::table('ventas', function (Blueprint $table) {
            $table->foreignId('cliente_id')->nullable()->after('user_id')->constrained('clientes')->nullOnDelete();
            // Las ventas anteriores a la facturación quedan como RECIBO.
            $table->string('tipo_comprobante', 10)->default('RECIBO')->after('estado')->index();
            $table->unsignedBigInteger('numero_factura')->nullable()->unique()->after('tipo_comprobante');
            $table->string('tipo_documento', 5)->nullable()->after('numero_factura');
            $table->string('numero_documento', 20)->nullable()->after('tipo_documento');
            $table->string('complemento', 5)->nullable()->after('numero_documento');
            $table->string('cliente_nombre', 500)->nullable()->after('complemento');
            $table->string('cliente_email')->nullable()->after('cliente_nombre');
            // 1 = el NIT no está en el padrón y se factura igual (excepción de validación).
            $table->unsignedTinyInteger('codigo_excepcion')->nullable()->after('cliente_email');
            // PENDIENTE, VALIDADA, OBSERVADA, PENDIENTE_EVENTO, ANULADA
            $table->string('estado_siat', 30)->nullable()->index()->after('codigo_excepcion');
            $table->unsignedTinyInteger('tipo_emision')->nullable()->after('estado_siat');
            $table->string('cuf', 100)->nullable()->index()->after('tipo_emision');
            $table->string('cufd', 150)->nullable()->after('cuf');
            $table->string('codigo_recepcion')->nullable()->after('cufd');
            $table->string('leyenda', 200)->nullable()->after('codigo_recepcion');
            $table->string('xml_path')->nullable()->after('leyenda');
            $table->text('siat_mensaje')->nullable()->after('xml_path');
            $table->dateTime('fecha_emision_siat')->nullable()->after('siat_mensaje');
            $table->foreignId('siat_evento_id')->nullable()->after('fecha_emision_siat')
                ->constrained('siat_eventos_significativos')->nullOnDelete();
        });

        foreach ($this->permisos as $permiso) {
            Permission::firstOrCreate(['name' => $permiso, 'guard_name' => 'web']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        User::where('username', 'admin')->first()?->givePermissionTo($this->permisos);
    }

    public function down(): void
    {
        Schema::table('ventas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('siat_evento_id');
            $table->dropConstrainedForeignId('cliente_id');
            $table->dropUnique(['numero_factura']);
            $table->dropIndex(['tipo_comprobante']);
            $table->dropIndex(['estado_siat']);
            $table->dropIndex(['cuf']);
            $table->dropColumn([
                'tipo_comprobante', 'numero_factura', 'tipo_documento', 'numero_documento', 'complemento',
                'cliente_nombre', 'cliente_email', 'codigo_excepcion', 'estado_siat', 'tipo_emision', 'cuf',
                'cufd', 'codigo_recepcion', 'leyenda', 'xml_path', 'siat_mensaje', 'fecha_emision_siat',
            ]);
        });
        Schema::table('categorias', fn (Blueprint $table) => $table->dropColumn(['actividad_economica', 'codigo_producto_sin']));
        Schema::dropIfExists('siat_catalogos');
        Schema::dropIfExists('siat_eventos_significativos');
        Schema::dropIfExists('siat_cufds');
        Schema::dropIfExists('siat_cuis');
        Schema::dropIfExists('siat_tokens');
        Schema::dropIfExists('clientes');
        Permission::whereIn('name', $this->permisos)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
