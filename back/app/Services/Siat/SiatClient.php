<?php

namespace App\Services\Siat;

use App\Models\SiatToken;
use RuntimeException;
use SoapClient;

/**
 * Única puerta hacia los servicios SOAP del SIN. Todo lo demás arma solicitudes y
 * lee respuestas; aquí se resuelve el token y se distingue "Impuestos no respondió"
 * (SiatSinConexionException) de "Impuestos respondió y rechazó".
 *
 * Los tests la reemplazan en el contenedor por una versión falsa.
 */
class SiatClient
{
    /** @var array<string, SoapClient> */
    private array $clients = [];

    /**
     * Devuelve el objeto Respuesta* de la operación (RespuestaCuis, RespuestaServicioFacturacion…).
     */
    public function call(string $service, string $method, array $payload): object
    {
        if (! class_exists(SoapClient::class)) {
            throw new RuntimeException('La extensión PHP SOAP no está habilitada');
        }

        try {
            $result = $this->client($service)->{$method}($payload);
        } catch (\Throwable $exception) {
            error_log('[SIAT] Sin respuesta: '.json_encode(['servicio' => $service, 'operacion' => $method, 'error' => $exception->getMessage()], JSON_UNESCAPED_UNICODE));
            throw new SiatSinConexionException('No hay conexión con Impuestos: '.$exception->getMessage(), 0, $exception);
        }

        $vars = get_object_vars($result);
        $response = count($vars) === 1 ? reset($vars) : $result;
        error_log('[SIAT] '.$service.'.'.$method.': '.json_encode([
            'transaccion' => $response->transaccion ?? null, 'codigo_estado' => $response->codigoEstado ?? null,
            'codigo_recepcion' => $response->codigoRecepcion ?? null, 'mensaje' => self::mensaje($response),
        ], JSON_UNESCAPED_UNICODE));

        return $response;
    }

    public function token(): string
    {
        $token = SiatToken::where('vence_en', '>', now())->latest('id')->first()?->token_cifrado ?: config('siat.token');
        if (! $token) {
            throw new RuntimeException('No hay un token SIAT vigente. Regístrelo en Impuestos.');
        }

        return $token;
    }

    /** Descripciones de mensajesList unidas en una línea. */
    public static function mensaje(object $response): ?string
    {
        $messages = $response->mensajesList ?? [];
        $messages = is_array($messages) ? $messages : [$messages];

        return implode('; ', array_filter(array_map(fn ($message) => $message->descripcion ?? null, $messages))) ?: null;
    }

    /** Las listas de SOAP llegan como objeto suelto cuando traen un solo elemento. */
    public static function lista(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        return is_array($value) ? $value : [$value];
    }

    private function client(string $service): SoapClient
    {
        return $this->clients[$service] ??= new SoapClient(config('siat.base_url').$service.'?wsdl', [
            'stream_context' => stream_context_create(['http' => ['header' => 'apikey: TokenApi '.$this->token(), 'timeout' => 20]]),
            'connection_timeout' => 15,
            'cache_wsdl' => WSDL_CACHE_MEMORY,
            'compression' => SOAP_COMPRESSION_ACCEPT | SOAP_COMPRESSION_GZIP | SOAP_COMPRESSION_DEFLATE,
            'trace' => true,
            'exceptions' => true,
        ]);
    }
}
