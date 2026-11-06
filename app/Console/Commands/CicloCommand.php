<?php

namespace App\Console\Commands;

use App\Servicios\Ciclo;
use Illuminate\Console\Command;

class CicloCommand extends Command
{
    protected $signature = 'alfavia:ciclo {--forzar : Recalcula las predicciones ahora mismo}';
    protected $description = 'Detecta estaciones caídas, recalcula las predicciones de IA y limpia datos antiguos';

    public function handle(): int
    {
        $this->line(json_encode(Ciclo::tick((bool) $this->option('forzar')), JSON_UNESCAPED_UNICODE));
        return self::SUCCESS;
    }
}
