<?php

namespace App\Services\Siat;

use RuntimeException;

/** Impuestos no respondió (sin internet, servicio caído, tiempo agotado). */
class SiatSinConexionException extends RuntimeException {}
