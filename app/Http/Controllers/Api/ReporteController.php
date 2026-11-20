<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Servicios\Audit;
use App\Servicios\Db;
use App\Servicios\Exportador;
use Illuminate\Http\Request;

/** Reporte diario de monitoreo en tiempo real: vista, historial por día y descarga en PDF, Excel o CSV. */
final class ReporteController extends BaseApi
{
    private function dia(Request $r): string
    {
        $f = $this->fecha($r->query('fecha', ''));
        $ok = $f !== '' && strtotime($f) && $f <= date('Y-m-d');
        return $ok ? $f : date('Y-m-d');
    }

    /** Reúne los datos del día consultando la base en el momento (por eso el reporte siempre está al día). */
    private function datos(string $dia): array
    {
        $p = ['d' => $dia];
        $ini = 'CAST(:d AS date)';
        $fin = "(CAST(:d AS date) + interval '1 day')";
        $eventos = Db::todos("SELECT m.fecha, e.codigo_activo, m.titulo, m.detalle FROM v_movimientos m JOIN estaciones e ON e.id = m.estacion_id
            WHERE m.fecha >= $ini AND m.fecha < $fin ORDER BY m.fecha", $p);
        $resumen = Db::fila("SELECT
           (SELECT count(*) FROM estaciones WHERE activo AND estado <> 'baja') AS total,
           (SELECT count(*) FROM eventos_conexion WHERE tipo='desconectada' AND detectado_en >= $ini AND detectado_en < $fin) AS desconexiones,
           (SELECT count(*) FROM eventos_dispositivo WHERE tipo='desconectado' AND detectado_en >= $ini AND detectado_en < $fin) AS disp_desc,
           (SELECT count(*) FROM movimientos_componentes WHERE fecha >= $ini AND fecha < $fin) AS cambios,
           (SELECT count(*) FROM alertas WHERE creada_en >= $ini AND creada_en < $fin) AS alertas,
           (SELECT count(*) FROM sesiones_pantalla WHERE inicio >= $ini AND inicio < $fin) AS vistas", $p) ?: [];
        $riesgo = Db::todos("SELECT e.codigo_activo, u.nombre AS ubicacion, x.prob_falla, x.nivel, x.causa_probable
           FROM (SELECT DISTINCT ON (estacion_id) * FROM predicciones WHERE calculado_en < $fin ORDER BY estacion_id, calculado_en DESC) x
           JOIN estaciones e ON e.id = x.estacion_id JOIN ubicaciones u ON u.id = e.ubicacion_id
           WHERE e.activo AND x.nivel <> 'normal' ORDER BY x.prob_falla DESC", $p);
        $alertas = Db::todos("SELECT a.creada_en, e.codigo_activo, a.severidad, a.mensaje, a.atendida_en FROM alertas a LEFT JOIN estaciones e ON e.id = a.estacion_id
           WHERE a.creada_en >= $ini AND a.creada_en < $fin ORDER BY a.creada_en", $p);
        return ['resumen' => array_map('intval', $resumen), 'riesgo' => $riesgo, 'eventos' => $eventos, 'alertas' => $alertas];
    }

    public function diario(Request $r)
    {
        $dia = $this->dia($r);
        $formato = (string) $r->query('formato', '');
        if (in_array($formato, ['pdf', 'xlsx', 'csv'], true)) {
            return $this->exportar($r, $dia, $formato);
        }
        $d = $this->datos($dia);
        Audit::registrar('reporte.ver', 'reporte', $dia);
        return $this->ok($d + [
            'fecha' => $dia, 'fecha_texto' => fd($dia), 'hoy' => $dia === date('Y-m-d'),
            'generado_por' => $this->usuario($r)['nombre_completo'], 'generado_en' => fh(date('c'), true),
        ]);
    }

    /** Historial: resumen de cada uno de los últimos N días y las últimas descargas de reportes. */
    public function historial(Request $r)
    {
        $dias = max(1, min(90, (int) $r->query('dias', 30)));
        $filas = Db::todos("SELECT to_char(d, 'YYYY-MM-DD') AS fecha,
              (SELECT count(*) FROM eventos_conexion WHERE tipo='desconectada' AND detectado_en >= d AND detectado_en < d + interval '1 day') AS desconexiones,
              (SELECT count(*) FROM eventos_dispositivo WHERE tipo='desconectado' AND detectado_en >= d AND detectado_en < d + interval '1 day') AS disp_desc,
              (SELECT count(*) FROM movimientos_componentes WHERE fecha >= d AND fecha < d + interval '1 day') AS cambios,
              (SELECT count(*) FROM alertas WHERE creada_en >= d AND creada_en < d + interval '1 day') AS alertas,
              (SELECT count(*) FROM sesiones_pantalla WHERE inicio >= d AND inicio < d + interval '1 day') AS vistas
            FROM generate_series(CAST(current_date AS timestamp) - make_interval(days => :n), CAST(current_date AS timestamp), interval '1 day') AS d
            ORDER BY d DESC", ['n' => $dias - 1]);
        $descargas = Db::todos("SELECT a.creado_en, a.accion, a.entidad_id AS fecha, COALESCE(a.usuario_nombre, 'sistema') AS usuario
            FROM auditoria a WHERE a.accion IN ('reporte.pdf','reporte.xlsx','reporte.csv') ORDER BY a.creado_en DESC LIMIT 15");
        foreach ($filas as &$f) {
            foreach (['desconexiones', 'disp_desc', 'cambios', 'alertas', 'vistas'] as $k) { $f[$k] = (int) $f[$k]; }
            $f['hoy'] = $f['fecha'] === date('Y-m-d');
            $f['texto'] = fd($f['fecha']);
        }
        unset($f);
        foreach ($descargas as &$x) {
            $x['formato'] = strtoupper(substr((string) $x['accion'], 8));
            $x['cuando'] = fh($x['creado_en'], true);
        }
        unset($x);
        return $this->ok(['dias' => $filas, 'descargas' => $descargas, 'ahora' => fh(date('c'), true)]);
    }

    private function exportar(Request $r, string $dia, string $formato)
    {
        $d = $this->datos($dia);
        $quien = $this->usuario($r)['nombre_completo'];
        $ahora = fh(date('c'), true);
        Audit::registrar('reporte.' . $formato, 'reporte', $dia);
        $nombre = 'reporte-' . $dia . '.' . $formato;
        $cab = static fn (string $tipo) => ['Content-Type' => $tipo, 'Content-Disposition' => 'attachment; filename="' . $nombre . '"', 'Cache-Control' => 'no-store'];
        $seguro = static fn ($v) => preg_match('/^[=+\-@\t\r]/', (string) $v) ? "'" . $v : (string) $v;   // evita fórmulas en Excel/CSV
        $res = $d['resumen'];
        $kpis = [['Estaciones activas', $res['total'] ?? 0], ['Desconexiones', $res['desconexiones'] ?? 0], ['Dispositivos desconectados', $res['disp_desc'] ?? 0],
                 ['Cambios de componentes', $res['cambios'] ?? 0], ['Alertas', $res['alertas'] ?? 0], ['Vistas de pantalla', $res['vistas'] ?? 0]];
        $filasRiesgo = array_map(static fn ($x) => [$x['codigo_activo'], $x['ubicacion'], round((float) $x['prob_falla'] * 100), $x['nivel'], $x['causa_probable'] ?? ''], $d['riesgo']);
        $filasEv = array_map(static fn ($e) => [fh($e['fecha'], true), $e['codigo_activo'], $seguro($e['titulo']), $seguro($e['detalle'])], $d['eventos']);
        $filasAl = array_map(static fn ($a) => [fh($a['creada_en'], true), $a['codigo_activo'] ?? '—', $a['severidad'], $seguro($a['mensaje']), $a['atendida_en'] ? 'Sí' : 'No'], $d['alertas']);

        if ($formato === 'csv') {
            $o = fopen('php://temp', 'w+');
            fwrite($o, "\xEF\xBB\xBF");
            fputcsv($o, ['Fecha', 'Estación', 'Evento', 'Detalle'], ',', '"', '');
            foreach ($filasEv as $f) { fputcsv($o, $f, ',', '"', ''); }
            rewind($o);
            return response((string) stream_get_contents($o), 200, $cab('text/csv; charset=utf-8'));
        }
        if ($formato === 'xlsx') {
            $bytes = Exportador::xlsx([
                'Resumen' => ['cabecera' => ['Indicador', 'Valor'], 'filas' => array_merge($kpis, [['', ''], ['Fecha del reporte', fd($dia)], ['Generado por', $quien], ['Generado el', $ahora]])],
                'Riesgo IA' => ['cabecera' => ['Estación', 'Ubicación', 'Prob. de falla 7 días (%)', 'Nivel', 'Causa probable'], 'filas' => $filasRiesgo],
                'Movimientos' => ['cabecera' => ['Fecha y hora', 'Estación', 'Evento', 'Detalle'], 'filas' => $filasEv],
                'Alertas' => ['cabecera' => ['Fecha y hora', 'Estación', 'Severidad', 'Mensaje', 'Atendida'], 'filas' => $filasAl],
            ]);
            return response($bytes, 200, $cab('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'));
        }
        $bytes = Exportador::pdf('AlfaVia S.A.C. - Reporte diario de monitoreo', fd($dia), [
            ['titulo' => 'Resumen del dia', 'cabecera' => ['Indicador', 'Valor'], 'filas' => $kpis],
            ['titulo' => 'Estaciones con riesgo (IA)', 'cabecera' => ['Estacion', 'Ubicacion', 'Prob. falla 7d (%)', 'Nivel', 'Causa probable'], 'filas' => $filasRiesgo],
            ['titulo' => 'Movimientos del dia', 'cabecera' => ['Hora', 'Estacion', 'Evento', 'Detalle'], 'filas' => $filasEv],
            ['titulo' => 'Alertas', 'cabecera' => ['Hora', 'Estacion', 'Severidad', 'Mensaje', 'Atendida'], 'filas' => $filasAl],
            ['titulo' => 'Nota', 'texto' => ['Las probabilidades son estimaciones del modelo de machine learning, no certezas.']],
        ], "Generado por $quien el $ahora (datos consultados en ese momento)");
        return response($bytes, 200, $cab('application/pdf'));
    }
}
