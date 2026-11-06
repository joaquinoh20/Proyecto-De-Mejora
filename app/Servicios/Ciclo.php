<?php
declare(strict_types=1);

namespace App\Servicios;

/**
 * Tareas periódicas del monitoreo. Se ejecuta sola cuando alguien usa el panel
 * (api/dashboard.php) y también desde la consola: php bin/ciclo.php (recomendado en un cron cada minuto).
 *   1. Marca como desconectadas las estaciones que dejaron de reportar.
 *   2. Cierra las vistas de pantalla vencidas.
 *   3. Calcula la probabilidad de falla de cada estación con el modelo de ML y genera alertas.
 *   4. Limpia datos antiguos según las políticas de retención.
 */
final class Ciclo
{
    private const BLOQUEO = 7001;
    private const FEATURES = ['cpu_prom', 'cpu_p95', 'ram_prom', 'ram_max', 'temp_prom', 'temp_max',
        'disco_usado_max', 'disco_salud_min', 'desconexiones_7d', 'dispositivos_7d', 'edad_meses', 'cambios_90d'];

    public static function tick(bool $forzar = false): array
    {
        if (!(bool) Db::valor('SELECT pg_try_advisory_lock(:k)', ['k' => self::BLOQUEO])) {
            return ['ocupado' => true];
        }
        try {
            $r = [
                'desconectadas' => self::detectarDesconexiones(),
                'pantallas_cerradas' => self::cerrarPantallas(),
            ];
            $ultimo = Db::valor("SELECT valor FROM ciclo_estado WHERE clave = 'prediccion'");
            if ($forzar || !$ultimo || strtotime((string) $ultimo) < time() - Politicas::get('ia_ciclo_segundos')) {
                Db::ejecutar("INSERT INTO ciclo_estado (clave, valor) VALUES ('prediccion', now())
                              ON CONFLICT (clave) DO UPDATE SET valor = now()");
                $r['predicciones'] = self::predecir();
                self::mantenimiento();
            }
            return $r;
        } finally {
            Db::valor('SELECT pg_advisory_unlock(:k)', ['k' => self::BLOQUEO]);
        }
    }

    private static function detectarDesconexiones(): int
    {
        return Db::ejecutar(
            "WITH caidas AS (
                UPDATE estaciones SET estado = 'sin_conexion'
                 WHERE estado = 'en_linea' AND activo
                   AND (ultimo_latido IS NULL OR ultimo_latido < now() - make_interval(secs => :s))
                RETURNING id, codigo_activo, ultimo_latido),
             ev AS (
                INSERT INTO eventos_conexion (estacion_id, tipo, detectado_en, detalle)
                SELECT id, 'desconectada', now(), 'Sin latidos del agente' FROM caidas RETURNING 1)
             INSERT INTO alertas (estacion_id, tipo, severidad, mensaje)
             SELECT id, 'desconexion', 'alta',
                    'La estación ' || codigo_activo || ' dejó de reportar (último latido ' ||
                    COALESCE(to_char(ultimo_latido, 'DD/MM HH24:MI'), 'nunca') || ')'
               FROM caidas",
            ['s' => Politicas::get('offline_tras_seg')]
        );
    }

    private static function cerrarPantallas(): int
    {
        $filas = Db::todos("UPDATE sesiones_pantalla SET fin = now(), motivo_fin = 'expirada'
                             WHERE fin IS NULL AND expira_en < now() RETURNING id, estacion_id, solicitado_por");
        foreach ($filas as $f) {
            Audit::registrar('pantalla.expirada', 'estacion', $f['estacion_id'], ['sesion' => $f['id']], (int) $f['solicitado_por']);
            Pantallas::borrarCuadro((int) $f['estacion_id']);
        }
        return count($filas);
    }

    private static function predecir(): int
    {
        $cols = implode(', ', array_map(static fn ($c) => 'f.' . $c, self::FEATURES));
        $filas = Db::todos(
            "SELECT e.id, e.codigo_activo, $cols
               FROM estaciones e CROSS JOIN LATERAL fn_features_estacion(e.id, now()) f
              WHERE e.activo AND e.estado <> 'baja' AND f.muestras >= 3"
        );
        if ($filas === []) {
            return 0;
        }
        $items = [];
        foreach ($filas as $f) {
            $x = [];
            foreach (self::FEATURES as $c) {
                $x[$c] = $f[$c] === null ? null : (float) $f[$c];
            }
            $items[] = ['id' => (int) $f['id'], 'features' => $x];
        }
        try {
            $res = Ml::predecir($items);
            Db::ejecutar("DELETE FROM ciclo_estado WHERE clave = 'ml_error'");
        } catch (\Throwable $e) {
            Db::ejecutar("INSERT INTO ciclo_estado (clave, valor, nota) VALUES ('ml_error', now(), :n)
                          ON CONFLICT (clave) DO UPDATE SET valor = now(), nota = :n", ['n' => mb_substr($e->getMessage(), 0, 300)]);
            return 0;
        }

        $atencion = (float) Politicas::get('ia_umbral_atencion');
        $critico = (float) Politicas::get('ia_umbral_critico');
        $codigos = array_column($filas, 'codigo_activo', 'id');
        $n = 0;
        foreach ($res as $r) {
            $id = (int) $r['id'];
            $p = (float) $r['prob_falla'];
            $nivel = $p >= $critico ? 'critico' : ($p >= $atencion ? 'atencion' : 'normal');
            Db::ejecutar(
                'INSERT INTO predicciones (estacion_id, prob_falla, nivel, anomalia, causa_probable, factores, modelo_version)
                 VALUES (:e, :p, :n, :a, :c, CAST(:f AS jsonb), :v)',
                ['e' => $id, 'p' => $p, 'n' => $nivel, 'a' => $r['anomalia'] ?? null, 'c' => $r['causa_probable'] ?? null,
                 'f' => json_encode($r['factores'] ?? [], JSON_UNESCAPED_UNICODE), 'v' => (string) $r['modelo_version']]
            );
            $abierta = Db::valor("SELECT id FROM alertas WHERE estacion_id = :e AND tipo = 'prediccion' AND atendida_en IS NULL", ['e' => $id]);
            if ($nivel !== 'normal') {
                $msg = sprintf('%s: %d%% de probabilidad de falla en 7 días%s', $codigos[$id] ?? $id, round($p * 100),
                    !empty($r['causa_probable']) ? ' · ' . $r['causa_probable'] : '');
                $sev = $nivel === 'critico' ? 'alta' : 'media';
                if ($abierta) {
                    Db::ejecutar('UPDATE alertas SET mensaje = :m, severidad = :s WHERE id = :id', ['m' => $msg, 's' => $sev, 'id' => (int) $abierta]);
                } else {
                    Db::ejecutar("INSERT INTO alertas (estacion_id, tipo, severidad, mensaje) VALUES (:e, 'prediccion', :s, :m)", ['e' => $id, 's' => $sev, 'm' => $msg]);
                }
            } elseif ($abierta) {
                Db::ejecutar('UPDATE alertas SET atendida_en = now() WHERE id = :id', ['id' => (int) $abierta]);   // se cerró sola: el riesgo bajó
            }
            $n++;
        }
        return $n;
    }

    private static function mantenimiento(): void
    {
        $ultimo = Db::valor("SELECT valor FROM ciclo_estado WHERE clave = 'purga'");
        if ($ultimo && strtotime((string) $ultimo) > time() - 3600) {
            return;
        }
        Db::ejecutar("INSERT INTO ciclo_estado (clave, valor) VALUES ('purga', now()) ON CONFLICT (clave) DO UPDATE SET valor = now()");
        Db::ejecutar('DELETE FROM telemetria WHERE tomado_en < now() - make_interval(days => :d)', ['d' => Politicas::get('telemetria_retencion_dias')]);
        Db::ejecutar('DELETE FROM auditoria WHERE creado_en < now() - make_interval(days => :d)', ['d' => Politicas::get('auditoria_retencion_dias')]);
        Db::ejecutar("DELETE FROM predicciones WHERE calculado_en < now() - interval '30 days'");
        Db::ejecutar("DELETE FROM resumenes_ia WHERE creado_en < now() - interval '60 days'");
    }
}
