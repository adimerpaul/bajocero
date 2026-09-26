<?php

namespace App\Http\Controllers;

use App\Models\Categoria;
use App\Models\SiatCatalogo;
use App\Models\SiatCufd;
use App\Models\SiatCuis;
use App\Models\SiatEventoSignificativo;
use App\Models\SiatToken;
use App\Services\Siat\EventoSignificativoService;
use App\Services\Siat\SiatService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Pantalla Impuestos: credenciales, CUIS/CUFD, catálogos y eventos significativos. */
class SiatController extends Controller
{
    public function estado(Request $request, SiatService $siat, EventoSignificativoService $eventos)
    {
        $this->authorizeAccess($request);

        return response()->json([
            'comunicacion' => $request->boolean('comunicacion') ? $siat->verificarComunicacion() : null,
            'ambiente' => config('siat.ambiente') === 1 ? 'PRODUCCIÓN' : 'PRUEBAS PILOTO',
            'modalidad' => config('siat.modalidad') === 2 ? 'COMPUTARIZADA' : 'ELECTRÓNICA',
            'habilitado' => (bool) config('siat.enabled'),
            'nit' => config('siat.nit'),
            'codigo_sistema' => config('siat.codigo_sistema'),
            'sucursal' => config('siat.sucursal'),
            'punto_venta' => config('siat.punto_venta'),
            'municipio' => config('siat.municipio'),
            'cuis' => $siat->cuisVigente(),
            'cufd' => $siat->cufdVigente(),
            'pendientes_evento' => $eventos->pendientes()->count(),
            'catalogos' => SiatCatalogo::selectRaw('tipo, count(*) as cantidad')->groupBy('tipo')->pluck('cantidad', 'tipo'),
        ]);
    }

    public function tokens(Request $request)
    {
        $this->authorizeAccess($request);

        return response()->json(SiatToken::latest('id')->get());
    }

    public function storeToken(Request $request)
    {
        $this->authorizeAccess($request);
        $data = $request->validate(['token' => ['required', 'string', 'max:20000']]);
        $parts = explode('.', trim($data['token']));
        abort_unless(count($parts) === 3, 422, 'El token no tiene formato JWT');
        $payload = json_decode((string) base64_decode(strtr($parts[1], '-_', '+/')), true);
        abort_unless(is_numeric($payload['exp'] ?? null), 422, 'El token no trae fecha de vencimiento');

        return response()->json(SiatToken::create(['token_cifrado' => trim($data['token']), 'vence_en' => date('Y-m-d H:i:s', (int) $payload['exp'])]), 201);
    }

    public function destroyToken(Request $request, SiatToken $token)
    {
        $this->authorizeAccess($request);
        $token->delete();

        return response()->noContent();
    }

    public function cuis(Request $request)
    {
        $this->authorizeAccess($request);

        return response()->json(SiatCuis::latest('id')->limit(20)->get());
    }

    public function generarCuis(Request $request, SiatService $siat)
    {
        $this->authorizeAccess($request);

        return $this->attempt(fn () => $siat->obtenerCuis($request->boolean('forzar')));
    }

    public function cufds(Request $request)
    {
        $this->authorizeAccess($request);

        return response()->json(SiatCufd::latest('id')->paginate(15));
    }

    public function generarCufd(Request $request, SiatService $siat)
    {
        $this->authorizeAccess($request);

        return $this->attempt(fn () => $siat->obtenerCufd($request->boolean('forzar')));
    }

    public function sincronizar(Request $request, SiatService $siat)
    {
        $this->authorizeAccess($request);

        return $this->attempt(fn () => ['catalogos' => $siat->sincronizar()]);
    }

    public function catalogo(Request $request, string $tipo)
    {
        $this->authorizeAccess($request);

        return response()->json(SiatCatalogo::where('tipo', mb_strtoupper($tipo))->orderBy('codigo_actividad')->orderBy('descripcion')->get());
    }

    public function categorias(Request $request)
    {
        $this->authorizeAccess($request);

        return response()->json(Categoria::withCount('productos')->orderBy('nombre')->get(['id', 'nombre', 'actividad_economica', 'codigo_producto_sin']));
    }

    public function updateCategoria(Request $request, Categoria $categoria)
    {
        $this->authorizeAccess($request);
        $data = $request->validate([
            'actividad_economica' => ['nullable', 'string', 'max:20'],
            'codigo_producto_sin' => ['nullable', 'integer'],
        ]);
        $categoria->update($data);

        return response()->json($categoria->fresh());
    }

    public function eventos(Request $request, EventoSignificativoService $service)
    {
        $this->authorizeAccess($request);

        return response()->json([
            'motivos' => collect(EventoSignificativoService::MOTIVOS)->map(fn ($descripcion, $codigo) => compact('codigo', 'descripcion'))->values(),
            'pendientes' => $service->pendientes()->orderBy('fecha_emision_siat')
                ->get(['id', 'numero', 'numero_factura', 'fecha_emision_siat', 'cliente_nombre', 'total', 'cufd', 'siat_mensaje']),
            'eventos' => SiatEventoSignificativo::latest('id')->limit(50)->get(),
        ]);
    }

    public function enviarEventos(Request $request, EventoSignificativoService $service)
    {
        $this->authorizeAccess($request);
        $data = $request->validate([
            'codigo_motivo' => ['required', 'integer', Rule::in(array_keys(EventoSignificativoService::MOTIVOS))],
            'descripcion' => ['nullable', 'string', 'max:500'],
        ]);

        return $this->attempt(fn () => ['eventos' => $service->enviarPendientes((int) $data['codigo_motivo'], $data['descripcion'] ?? null, $request->user()->id)]);
    }

    public function revalidarEvento(Request $request, SiatEventoSignificativo $evento, EventoSignificativoService $service)
    {
        $this->authorizeAccess($request);

        return $this->attempt(fn () => $service->revalidar($evento));
    }

    /** Los errores del SIN llegan como 422 con su mensaje, no como 500. */
    private function attempt(callable $action)
    {
        try {
            return response()->json($action());
        } catch (HttpException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            report($exception);
            abort(422, $exception->getMessage());
        }
    }

    private function authorizeAccess(Request $request): void
    {
        abort_unless($request->user()?->hasPermissionTo('Gestionar Impuestos'), 403, 'No tiene permiso para realizar esta acción');
    }
}
