<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Rendimiento en vivo y hardware real de cada estación.
 *  · recibir(): lo llama el agente cada pocos segundos (token de la estación, sin sesión).
 *  · ver():     lo consulta el panel React (sesión + permiso estaciones.ver).
 * Se guarda solo la última lectura (una fila por estación); el historial sigue en «telemetria» (latido de 15 s).
 */
final class RendimientoController extends BaseApi
{
    private function tabla(): void
    {
        Cache::remember('alfavia_tabla_rendimiento', 3600, function () {
            DB::statement("CREATE TABLE IF NOT EXISTS estacion_rendimiento (
                estacion_id    integer PRIMARY KEY REFERENCES estaciones(id) ON DELETE CASCADE,
                actualizado_en timestamptz NOT NULL DEFAULT now(),
                rendimiento    jsonb NOT NULL DEFAULT '{}'::jsonb,
                hardware       jsonb)");
            return true;
        });
    }

    private function texto(mixed $v, int $max = 120): ?string
    {
        $v = is_scalar($v) ? trim((string) $v) : '';
        return $v === '' ? null : mb_substr($v, 0, $max);
    }

    private function entero(mixed $v, int $min, int $max): ?int
    {
        return is_numeric($v) && $v >= $min && $v <= $max ? (int) round((float) $v) : null;
    }

    /** POST /api/agent/rendimiento */
    public function recibir(Request $r)
    {
        $codigo = trim((string) $r->header('X-Equipo', ''));
        $token = preg_match('/^Bearer\s+(\S+)$/i', (string) $r->header('Authorization', ''), $m) ? $m[1] : '';
        if ($token === '') {
            return response()->json(['ok' => false, 'error' => 'No autorizado.'], 401);
        }
        $est = DB::selectOne(
            'SELECT id FROM estaciones WHERE agente_token_hash = ? AND activo' . ($codigo !== '' ? ' AND codigo_activo = ?' : ''),
            $codigo !== '' ? [hash('sha256', $token), $codigo] : [hash('sha256', $token)]
        );
        if (!$est) {
            return response()->json(['ok' => false, 'error' => 'No autorizado.'], 401);
        }
        if (strlen($r->getContent()) > 65536) {
            return response()->json(['ok' => false, 'error' => 'Datos demasiado grandes.'], 413);
        }
        $rend = $r->input('rendimiento');
        if (!is_array($rend) || !isset($rend['cpu'], $rend['ram'])) {
            return response()->json(['ok' => false, 'error' => 'Faltan datos de rendimiento.'], 422);
        }
        $this->tabla();
        $hw = $r->input('hardware');
        $hw = is_array($hw) ? json_encode($hw, JSON_UNESCAPED_UNICODE) : null;
        DB::statement(
            'INSERT INTO estacion_rendimiento (estacion_id, actualizado_en, rendimiento, hardware) VALUES (?, now(), ?::jsonb, ?::jsonb)
             ON CONFLICT (estacion_id) DO UPDATE SET actualizado_en = now(), rendimiento = EXCLUDED.rendimiento,
                 hardware = COALESCE(EXCLUDED.hardware, estacion_rendimiento.hardware)',
            [$est->id, json_encode($rend, JSON_UNESCAPED_UNICODE), $hw]
        );

        // Con el hardware real (una vez por hora) se completa la ficha de la estación
        $inv = $r->input('inventario');
        if (is_array($inv)) {
            $mac = strtoupper(str_replace('-', ':', (string) ($inv['mac'] ?? '')));
            DB::statement(
                'UPDATE estaciones SET
                    marca = COALESCE(?, marca), modelo = COALESCE(?, modelo), numero_serie = COALESCE(?, numero_serie),
                    cpu_modelo = COALESCE(?, cpu_modelo), cpu_nucleos = COALESCE(?::smallint, cpu_nucleos), ram_gb = COALESCE(?::smallint, ram_gb),
                    disco_tipo = COALESCE(?, disco_tipo), disco_gb = COALESCE(?::integer, disco_gb), gpu = COALESCE(?, gpu),
                    so = COALESCE(?, so), mac = COALESCE(?::macaddr, mac)
                  WHERE id = ?',
                [
                    $this->texto($inv['marca'] ?? null), $this->texto($inv['modelo'] ?? null), $this->texto($inv['numero_serie'] ?? null, 60),
                    $this->texto($inv['cpu_modelo'] ?? null), $this->entero($inv['cpu_nucleos'] ?? null, 1, 512), $this->entero($inv['ram_gb'] ?? null, 1, 32000),
                    $this->texto($inv['disco_tipo'] ?? null, 20), $this->entero($inv['disco_gb'] ?? null, 1, 2000000), $this->texto($inv['gpu'] ?? null, 200),
                    $this->texto($inv['so'] ?? null), preg_match('/^([0-9A-F]{2}:){5}[0-9A-F]{2}$/', $mac) ? $mac : null,
                    $est->id,
                ]
            );
        }
        return response()->json(['ok' => true]);
    }

    /** GET /app-api/estaciones/{id}/rendimiento */
    public function ver(Request $r, int $id)
    {
        $this->tabla();
        $f = DB::selectOne(
            'SELECT rendimiento, hardware, extract(epoch FROM now() - actualizado_en) AS edad FROM estacion_rendimiento WHERE estacion_id = ?',
            [$id]
        );
        if (!$f) {
            return $this->ok(['hay_datos' => false, 'en_vivo' => false, 'edad_seg' => null, 'rendimiento' => null, 'hardware' => null]);
        }
        $edad = (int) round((float) $f->edad);
        return $this->ok([
            'hay_datos' => true,
            'en_vivo' => $edad <= 10,
            'edad_seg' => $edad,
            'rendimiento' => json_decode((string) $f->rendimiento, true),
            'hardware' => $f->hardware ? json_decode((string) $f->hardware, true) : null,
        ]);
    }
}
