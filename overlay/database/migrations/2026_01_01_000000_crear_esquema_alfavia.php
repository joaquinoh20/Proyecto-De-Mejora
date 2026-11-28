<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Crea el esquema de AlfaVía (22 tablas, 2 vistas y la función fn_features_estacion que usa el modelo de ML)
 * y carga los datos base: roles, permisos y políticas de seguridad.
 * El SQL está en database/sql para poder leerlo y exponerlo; cada archivo trae su propia transacción.
 */
return new class extends Migration
{
    public bool $withinTransaction = false;

    public function up(): void
    {
        DB::unprepared('DROP FUNCTION IF EXISTS fn_features_estacion(integer, timestamptz)');
        foreach (['01_schema.sql', '02_base.sql'] as $archivo) {
            DB::unprepared((string) file_get_contents(database_path('sql/' . $archivo)));
        }
    }

    public function down(): void
    {
        DB::unprepared('DROP SCHEMA public CASCADE; CREATE SCHEMA public;');
    }
};
