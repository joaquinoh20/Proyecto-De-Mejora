<?php

use Illuminate\Support\Facades\Schedule;

// Ciclo de monitoreo: detecta equipos caídos, recalcula las predicciones de IA y limpia datos antiguos.
// Para que corra solo:  php artisan schedule:work   (en producción, el cron de Laravel)
Schedule::command('alfavia:ciclo')->everyMinute()->withoutOverlapping();
