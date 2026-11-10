<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

final class EvaluateVaccinationAlertsCommand extends Command
{
    protected $signature = 'manejo:evaluar-vacunaciones';

    protected $description = 'Evalúa las vacunaciones programadas y sincroniza los avisos de Planes de manejo';

    public function handle(): int
    {
        return $this->call('manejo:evaluar-sanidad', ['--solo-vacunas' => true]);
    }
}
