<?php
declare(strict_types=1);

namespace App\Servicios;

/** Estado operativo de una estación a partir de su conexión, su última telemetría y la predicción del modelo. */
final class Operatividad
{
    /** Segundos sin latido para considerar que el equipo perdió la conexión. */
    public const LIMITE_LATIDO = 120;

    /**
     * @return array{nivel: string, etiqueta: string, clase: string, motivos: string[], conectado: bool}
     */
    public static function evaluar(array $e, ?array $ult, ?array $pred): array
    {
        $latido = $e['ultimo_latido'] ?? null;
        $conectado = $latido && (time() - strtotime($latido)) <= self::LIMITE_LATIDO;
        $m = [];
        if (($e['estado'] ?? '') === 'baja') {
            return ['nivel' => 'baja', 'etiqueta' => 'De baja', 'clase' => 'off', 'motivos' => ['La estación fue dada de baja.'], 'conectado' => false];
        }
        if (($e['estado'] ?? '') === 'mantenimiento') {
            return ['nivel' => 'mantenimiento', 'etiqueta' => 'En mantenimiento', 'clase' => 'wn', 'motivos' => ['La estación está marcada en mantenimiento.'], 'conectado' => (bool) $conectado];
        }
        if (!$latido) {
            return ['nivel' => 'sin_sincronizar', 'etiqueta' => 'Sin sincronizar', 'clase' => 'off', 'motivos' => ['Aún no se recibió ningún reporte de este equipo. Instala el agente en la PC.'], 'conectado' => false];
        }
        if (!$conectado) {
            return ['nivel' => 'sin_conexion', 'etiqueta' => 'Sin conexión', 'clase' => 'rk', 'motivos' => ['No reporta desde ' . hace($latido) . '.'], 'conectado' => false];
        }
        $crit = 0;
        $warn = 0;
        if ($ult) {
            $reglas = [
                ['cpu_pct', 95, 85, 'cpu_pct', 'CPU al %s%%'], ['ram_pct', 95, 85, 'ram_pct', 'RAM al %s%%'],
                ['temp_c', 85, 75, 'temp_c', 'Temperatura de %s °C'],
            ];
            foreach ($reglas as [$k, $c, $w, , $txt]) {
                $v = $ult[$k] ?? null;
                if ($v === null) { continue; }
                if ($v >= $c) { $crit++; $m[] = sprintf($txt, round((float) $v)) . ' (crítico)'; }
                elseif ($v >= $w) { $warn++; $m[] = sprintf($txt, round((float) $v)) . ' (alto)'; }
            }
            $s = $ult['disco_salud_pct'] ?? null;
            if ($s !== null) {
                if ($s < 30) { $crit++; $m[] = 'Salud del disco en ' . round((float) $s) . '% (crítico)'; }
                elseif ($s < 60) { $warn++; $m[] = 'Salud del disco en ' . round((float) $s) . '% (baja)'; }
            }
        } else {
            $m[] = 'Aún no hay mediciones.';
        }
        if ($pred && ($pred['nivel'] ?? '') === 'critico') { $crit++; $m[] = 'El modelo de IA estima riesgo crítico de falla.'; }
        elseif ($pred && ($pred['nivel'] ?? '') === 'atencion') { $warn++; $m[] = 'El modelo de IA recomienda atención.'; }

        if ($crit > 0) { return ['nivel' => 'critica', 'etiqueta' => 'Crítica', 'clase' => 'rk', 'motivos' => $m, 'conectado' => true]; }
        if ($warn > 0) { return ['nivel' => 'degradada', 'etiqueta' => 'Degradada', 'clase' => 'wn', 'motivos' => $m, 'conectado' => true]; }
        return ['nivel' => 'operativa', 'etiqueta' => 'Operativa', 'clase' => 'ok', 'motivos' => $m ?: ['Sin señales de problema.'], 'conectado' => true];
    }
}
