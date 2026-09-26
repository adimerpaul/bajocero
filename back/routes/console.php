<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// El CUFD vence a las 24 horas: se renueva de madrugada para empezar el día con uno nuevo.
Schedule::command('siat:renovar-cufd')->dailyAt('00:30')->timezone('America/La_Paz')->withoutOverlapping(30);
