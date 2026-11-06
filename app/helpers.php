<?php
declare(strict_types=1);

// Funciones de formato usadas por los servicios y los controladores.
// Se cargan desde bootstrap/app.php. No definas aquí nombres que Laravel ya usa (url, redirect, view…).

if (!function_exists('limpio')) {
    /** Texto de entrada limpio: recorta, quita caracteres de control y limita el largo. */
    function limpio(mixed $v, int $max = 200): string
    {
        $s = trim((string) $v);
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s) ?? '';
        return mb_substr($s, 0, $max);
    }
}

if (!function_exists('fh')) {
    /** Fecha y hora local legible: 26/10/2026 18:40 */
    function fh(?string $ts, bool $segundos = false): string
    {
        return $ts ? date($segundos ? 'd/m/Y H:i:s' : 'd/m/Y H:i', strtotime($ts)) : '—';
    }
}

if (!function_exists('fd')) {
    function fd(?string $ts): string
    {
        return $ts ? date('d/m/Y', strtotime($ts)) : '—';
    }
}

if (!function_exists('hace')) {
    function hace(?string $ts): string
    {
        if (!$ts) {
            return 'nunca';
        }
        $s = max(0, time() - strtotime($ts));
        if ($s < 60) {
            return 'hace unos segundos';
        }
        if ($s < 3600) {
            return 'hace ' . intdiv($s, 60) . ' min';
        }
        if ($s < 86400) {
            return 'hace ' . intdiv($s, 3600) . ' h';
        }
        return 'hace ' . intdiv($s, 86400) . ' d';
    }
}

if (!function_exists('duracion')) {
    function duracion(int $seg): string
    {
        if ($seg < 90) {
            return $seg . ' s';
        }
        if ($seg < 5400) {
            return round($seg / 60) . ' min';
        }
        return round($seg / 3600, 1) . ' h';
    }
}

if (!function_exists('etiqueta_estado')) {
    function etiqueta_estado(string $estado): array
    {
        return match ($estado) {
            'en_linea'      => ['En línea', 'ok'],
            'sin_conexion'  => ['Sin conexión', 'off'],
            'mantenimiento' => ['Mantenimiento', 'wn'],
            'baja'          => ['De baja', 'off'],
            default         => [$estado, 'off'],
        };
    }
}

if (!function_exists('etiqueta_nivel')) {
    function etiqueta_nivel(?string $nivel): array
    {
        return match ($nivel) {
            'critico'  => ['Crítico', 'rk'],
            'atencion' => ['Atención', 'wn'],
            'normal'   => ['Normal', 'ok'],
            default    => ['Sin dato', 'off'],
        };
    }
}
