<?php
declare(strict_types=1);

namespace App\Servicios;

/** Datos del panel de monitoreo (los usa la página y la API que lo actualiza en vivo). */
final class Panel
{
    public static function datos(): array
    {
        $filas = Db::todos(
            "SELECT e.id, e.codigo_activo, e.estado, e.ultimo_latido, u.nombre AS ubicacion, u.id AS ubicacion_id,
                    p.prob_falla, p.nivel, p.causa_probable, p.calculado_en
               FROM estaciones e
               JOIN ubicaciones u ON u.id = e.ubicacion_id
               LEFT JOIN v_ultima_prediccion p ON p.estacion_id = e.id
              WHERE e.activo AND e.estado <> 'baja'
              ORDER BY u.id, e.codigo_activo"
        );
        $estaciones = [];
        $enLinea = 0;
        $suma = 0.0;
        $nSuma = 0;
        $enRiesgo = 0;
        foreach ($filas as $f) {
            $clase = 'off';
            $valor = '—';
            $estado = 'Sin dato';
            if ($f['estado'] === 'sin_conexion') {
                $estado = 'Sin conexión';
            } elseif ($f['estado'] === 'mantenimiento') {
                $clase = 'wn';
                $estado = 'Mantenimiento';
            } else {
                $enLinea++;
                if ($f['nivel'] !== null) {
                    $valor = round((float) $f['prob_falla'] * 100) . '%';
                    [$estado, $clase] = etiqueta_nivel($f['nivel']);
                    $suma += (float) $f['prob_falla'];
                    $nSuma++;
                    if ($f['nivel'] !== 'normal') {
                        $enRiesgo++;
                    }
                }
            }
            $titulo = $f['codigo_activo'] . ' · ' . $estado
                . ($f['causa_probable'] && $f['nivel'] !== 'normal' ? ' · ' . $f['causa_probable'] : '')
                . ' · último latido ' . hace($f['ultimo_latido']);
            $estaciones[] = [
                'id' => (int) $f['id'], 'codigo' => $f['codigo_activo'], 'ubicacion' => $f['ubicacion'],
                'clase' => $clase, 'valor' => $valor, 'estado' => $estado, 'titulo' => $titulo,
            ];
        }
        $alertas = array_map(static fn ($a) => [
            'id' => (int) $a['id'], 'mensaje' => $a['mensaje'], 'severidad' => $a['severidad'], 'tipo' => $a['tipo'], 'cuando' => hace($a['creada_en']),
        ], Db::todos("SELECT * FROM alertas WHERE atendida_en IS NULL
                      ORDER BY CASE severidad WHEN 'alta' THEN 0 WHEN 'media' THEN 1 ELSE 2 END, creada_en DESC LIMIT 12"));

        $error = Db::fila("SELECT nota FROM ciclo_estado WHERE clave = 'ml_error'");
        $ver = Db::fila('SELECT modelo_version, calculado_en FROM predicciones ORDER BY calculado_en DESC LIMIT 1');
        if ($error) {
            $ml = ['texto' => 'Modelo de ML sin conexión', 'clase' => 'rk'];
        } elseif ($ver) {
            $ml = ['texto' => 'Modelo de ML activo · ' . $ver['modelo_version'] . ' · calculado ' . hace($ver['calculado_en']), 'clase' => 'ok'];
        } else {
            $ml = ['texto' => 'Aún sin predicciones', 'clase' => 'off'];
        }
        return [
            'ok' => true,
            'kpis' => [
                'linea' => $enLinea . '/' . count($estaciones),
                'alertas' => (string) (int) Db::valor('SELECT count(*) FROM alertas WHERE atendida_en IS NULL'),
                'riesgo' => $nSuma ? round($suma / $nSuma * 100) . '%' : '—',
                'atencion' => (string) $enRiesgo,
            ],
            'estaciones' => $estaciones,
            'alertas' => $alertas,
            'ml' => $ml,
        ];
    }
}
