<?php
declare(strict_types=1);

namespace App\Servicios;

/** Lee variables del archivo .env (Laravel ya lo cargó al arrancar). */
final class Config
{
    public static function get(string $clave, string $defecto = ''): string
    {
        $v = $_ENV[$clave] ?? $_SERVER[$clave] ?? getenv($clave);
        return ($v === false || $v === null || $v === '') ? $defecto : (string) $v;
    }

    public static function esDev(): bool
    {
        return in_array(app()->environment(), ['local', 'testing'], true);
    }

    /** Carpeta de almacenamiento privada (storage/app/alfavia). */
    public static function storage(): string
    {
        $d = storage_path('app/alfavia');
        if (!is_dir($d)) {
            mkdir($d, 0750, true);
        }
        return $d;
    }
}
