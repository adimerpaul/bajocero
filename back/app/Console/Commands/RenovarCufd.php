<?php

namespace App\Console\Commands;

use App\Services\Siat\SiatService;
use Illuminate\Console\Command;

class RenovarCufd extends Command
{
    protected $signature = 'siat:renovar-cufd';

    protected $description = 'Pide al SIN un CUFD nuevo (vence cada 24 horas)';

    public function handle(SiatService $siat): int
    {
        try {
            $cufd = $siat->obtenerCufd(true);
            $this->info("CUFD renovado. Vigente hasta {$cufd->vence_en}");

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            report($exception);
            $this->error('No se pudo renovar el CUFD: '.$exception->getMessage());

            return self::FAILURE;
        }
    }
}
