<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * DATOS DE DEMOSTRACIÓN (no son mediciones reales): 4 proveedores, 25 estaciones, 150 componentes,
 * 3 usuarios de prueba y 90 días de telemetría sintética para entrenar y probar el modelo de ML.
 * No ejecutar en producción:  php artisan db:seed --class=DemoSeeder
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['03_demo_inventario.sql', '04_demo_historial.sql'] as $archivo) {
            DB::unprepared((string) file_get_contents(database_path('sql/' . $archivo)));
        }
    }
}
