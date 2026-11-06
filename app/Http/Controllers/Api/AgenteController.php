<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Servicios\Agente;
use App\Servicios\Db;
use App\Servicios\Pantallas;
use App\Servicios\Politicas;
use Illuminate\Http\Request;

/**
 * Endpoints que usan los agentes instalados en las PCs (autenticados con el token de la estación, sin sesión).
 *   POST /api/agent/latido   métricas, hardware y dispositivos conectados
 *   POST /api/agent/pantalla fotograma JPEG (solo mientras alguien ve esa estación)
 */
final class AgenteController
{
    public function latido(Request $r)
    {
        $est = Agente::estacion($r);
        if (!$est) {
            return response()->json(['ok' => false, 'error' => 'Token no válido'], 401);
        }
        $crudo = $r->getContent();
        if (strlen($crudo) > 262144) {
            return response()->json(['ok' => false, 'error' => 'Cuerpo demasiado grande'], 413);
        }
        $d = json_decode($crudo, true);
        $d = is_array($d) ? $d : [];
        $eid = (int) $est['id'];

        /** Número dentro de un rango razonable, o null. */
        $num = static function (mixed $v, float $min, float $max): ?float {
            return is_numeric($v) && $v >= $min && $v <= $max ? round((float) $v, 1) : null;
        };
        $m = is_array($d['metricas'] ?? null) ? $d['metricas'] : [];
        $hw = is_array($d['hardware'] ?? null) ? $d['hardware'] : [];
        $ip = filter_var($d['ip'] ?? '', FILTER_VALIDATE_IP) ?: null;
        $mac = preg_match('/^([0-9A-Fa-f]{2}[:-]){5}[0-9A-Fa-f]{2}$/', (string) ($d['mac'] ?? '')) ? str_replace('-', ':', (string) $d['mac']) : null;

        Db::transaccion(function () use ($est, $eid, $d, $m, $hw, $ip, $mac, $num): void {
            Db::ejecutar(
                'INSERT INTO telemetria (estacion_id, cpu_pct, ram_pct, temp_c, disco_usado_pct, disco_salud_pct, uptime_h)
                 VALUES (:e, :cpu, :ram, :t, :du, :ds, :up)',
                ['e' => $eid, 'cpu' => $num($m['cpu_pct'] ?? null, 0, 100), 'ram' => $num($m['ram_pct'] ?? null, 0, 100),
                 't' => $num($m['temp_c'] ?? null, -20, 150), 'du' => $num($m['disco_usado_pct'] ?? null, 0, 100),
                 'ds' => $num($m['disco_salud_pct'] ?? null, 0, 100), 'up' => $num($m['uptime_h'] ?? null, 0, 100000)]
            );

            // ¿Volvió después de estar caída?
            $umbral = Politicas::get('offline_tras_seg');
            $antes = $est['estado'];
            $ultimo = $est['ultimo_latido'] ? strtotime($est['ultimo_latido']) : null;
            $huboHueco = $ultimo !== null && (time() - $ultimo) > $umbral;
            if ($antes !== 'mantenimiento' && ($antes === 'sin_conexion' || $huboHueco)) {
                if ($antes === 'en_linea' && $huboHueco) {      // nadie abrió el panel mientras estuvo caída: se registra ahora
                    Db::ejecutar("INSERT INTO eventos_conexion (estacion_id, tipo, detectado_en, detalle)
                                  VALUES (:e, 'desconectada', to_timestamp(:t), 'Sin latidos del agente')", ['e' => $eid, 't' => $ultimo + $umbral]);
                }
                Db::ejecutar("INSERT INTO eventos_conexion (estacion_id, tipo, duracion_seg, detalle)
                              VALUES (:e, 'conectada', :s, 'El agente volvió a reportar')", ['e' => $eid, 's' => $ultimo ? time() - $ultimo : null]);
                Db::ejecutar("UPDATE alertas SET atendida_en = now() WHERE estacion_id = :e AND tipo = 'desconexion' AND atendida_en IS NULL", ['e' => $eid]);
            }

            Db::ejecutar(
                "UPDATE estaciones SET ultimo_latido = now(), agente_version = COALESCE(:v, agente_version),
                        estado = CASE WHEN estado = 'mantenimiento' THEN estado ELSE 'en_linea' END,
                        ip = COALESCE(CAST(:ip AS inet), ip), mac = COALESCE(CAST(:mac AS macaddr), mac),
                        so = COALESCE(:so, so), cpu_modelo = COALESCE(:cpu, cpu_modelo), cpu_nucleos = COALESCE(:nuc, cpu_nucleos),
                        ram_gb = COALESCE(:ram, ram_gb), disco_gb = COALESCE(:dgb, disco_gb), disco_tipo = COALESCE(:dt, disco_tipo)
                  WHERE id = :e",
                ['v' => limpio($d['agente_version'] ?? '', 20) ?: null, 'ip' => $ip, 'mac' => $mac,
                 'so' => limpio($d['so'] ?? '', 80) ?: null, 'cpu' => limpio($hw['cpu_modelo'] ?? '', 120) ?: null,
                 'nuc' => ($n = $num($hw['cpu_nucleos'] ?? null, 1, 512)) !== null ? (int) $n : null,
                 'ram' => ($rr = $num($hw['ram_gb'] ?? null, 1, 8192)) !== null ? (int) round($rr) : null,
                 'dgb' => ($g = $num($hw['disco_gb'] ?? null, 1, 1000000)) !== null ? (int) round($g) : null,
                 'dt' => in_array($hw['disco_tipo'] ?? '', ['SSD', 'HDD', 'NVMe'], true) ? $hw['disco_tipo'] : null,
                 'e' => $eid]
            );

            // Dispositivos: se compara lo que ve el agente con lo que había (conexión / desconexión)
            if (is_array($d['dispositivos'] ?? null)) {
                $vistos = [];
                foreach (array_slice($d['dispositivos'], 0, 80) as $x) {
                    if (!is_array($x)) {
                        continue;
                    }
                    $id = limpio($x['id'] ?? '', 120);
                    if ($id !== '') {
                        $vistos[$id] = ['nombre' => limpio($x['nombre'] ?? $id, 120), 'cat' => limpio($x['categoria'] ?? 'otro', 30) ?: 'otro'];
                    }
                }
                $previos = [];
                foreach (Db::todos('SELECT * FROM dispositivos_detectados WHERE estacion_id = :e', ['e' => $eid]) as $p) {
                    $previos[$p['identificador']] = $p;
                }
                $linea_base = $previos === [];
                foreach ($vistos as $id => $v) {
                    if (!isset($previos[$id])) {
                        Db::ejecutar('INSERT INTO dispositivos_detectados (estacion_id, identificador, nombre, categoria) VALUES (:e, :i, :n, :c)',
                            ['e' => $eid, 'i' => $id, 'n' => $v['nombre'], 'c' => $v['cat']]);
                        if (!$linea_base) {
                            Db::ejecutar("INSERT INTO eventos_dispositivo (estacion_id, identificador, nombre, categoria, tipo) VALUES (:e, :i, :n, :c, 'conectado')",
                                ['e' => $eid, 'i' => $id, 'n' => $v['nombre'], 'c' => $v['cat']]);
                        }
                    } elseif (!$previos[$id]['presente']) {
                        Db::ejecutar('UPDATE dispositivos_detectados SET presente = true, visto_ultima = now() WHERE id = :id', ['id' => (int) $previos[$id]['id']]);
                        Db::ejecutar("INSERT INTO eventos_dispositivo (estacion_id, identificador, nombre, categoria, tipo) VALUES (:e, :i, :n, :c, 'conectado')",
                            ['e' => $eid, 'i' => $id, 'n' => $previos[$id]['nombre'], 'c' => $previos[$id]['categoria']]);
                        Db::ejecutar("UPDATE alertas SET atendida_en = now() WHERE estacion_id = :e AND tipo = 'dispositivo' AND atendida_en IS NULL AND mensaje = :m",
                            ['e' => $eid, 'm' => 'Se desconectó el dispositivo ' . $previos[$id]['nombre']]);
                    }
                }
                foreach ($previos as $id => $p) {
                    if ($p['presente'] && !isset($vistos[$id])) {
                        Db::ejecutar('UPDATE dispositivos_detectados SET presente = false WHERE id = :id', ['id' => (int) $p['id']]);
                        Db::ejecutar("INSERT INTO eventos_dispositivo (estacion_id, identificador, nombre, categoria, tipo) VALUES (:e, :i, :n, :c, 'desconectado')",
                            ['e' => $eid, 'i' => $id, 'n' => $p['nombre'], 'c' => $p['categoria']]);
                        Db::ejecutar("INSERT INTO alertas (estacion_id, tipo, severidad, mensaje) VALUES (:e, 'dispositivo', 'media', :m)",
                            ['e' => $eid, 'm' => 'Se desconectó el dispositivo ' . $p['nombre']]);
                    }
                }
                if ($vistos) {
                    Db::ejecutar('UPDATE dispositivos_detectados SET visto_ultima = now() WHERE estacion_id = :e AND presente', ['e' => $eid]);
                }
            }
        });

        return response()->json([
            'ok' => true,
            'intervalo' => Politicas::get('latido_intervalo_seg'),
            'pantalla' => ['activa' => Pantallas::hayVista($eid), 'aviso_visible' => Politicas::get('pantalla_aviso_visible')],
        ]);
    }

    public function pantalla(Request $r)
    {
        $est = Agente::estacion($r);
        if (!$est) {
            return response()->json(['ok' => false, 'error' => 'Token no válido'], 401);
        }
        $eid = (int) $est['id'];
        // Si ya nadie está viendo esta estación, se le dice al agente que pare y no se guarda nada
        if (!Pantallas::hayVista($eid)) {
            return response()->json(['ok' => true, 'continuar' => false]);
        }
        // Con la política activa, el agente debe declarar que el aviso visible está en pantalla
        if (Politicas::get('pantalla_aviso_visible') && $r->header('X-Aviso-Visible') !== '1') {
            return response()->json(['ok' => false, 'continuar' => false, 'error' => 'El agente no confirmó el aviso visible en la estación'], 403);
        }
        $bytes = $r->getContent();
        if ($bytes === '' || strlen($bytes) > 3 * 1024 * 1024) {
            return response()->json(['ok' => false, 'error' => 'Imagen vacía o demasiado grande (máx. 3 MB)'], 413);
        }
        $info = @getimagesizefromstring($bytes);
        if ($info === false || $info[2] !== IMAGETYPE_JPEG || $info[0] > 4096 || $info[1] > 4096) {
            return response()->json(['ok' => false, 'error' => 'Solo se aceptan imágenes JPEG de hasta 4096 px'], 415);
        }
        Pantallas::guardarCuadro($eid, $bytes);
        Db::ejecutar('UPDATE sesiones_pantalla SET ultima_captura_en = now() WHERE estacion_id = :e AND fin IS NULL', ['e' => $eid]);
        return response()->json(['ok' => true, 'continuar' => true]);
    }
}
