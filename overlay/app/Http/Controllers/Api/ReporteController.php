<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Servicios\Audit;
use App\Servicios\Db;
use Illuminate\Http\Request;

/** Reporte diario de monitoreo (se imprime o guarda como PDF desde el navegador) y exportación CSV. */
final class ReporteController extends BaseApi
{
    public function diario(Request $r)
    {
        $f = $this->fecha($r->query('fecha', ''));
        $dia = ($f !== '' && strtotime($f)) ? $f : date('Y-m-d');
        $p = ['d' => $dia];
        $ini = 'CAST(:d AS date)';
        $fin = "(CAST(:d AS date) + interval '1 day')";

        $eventos = Db::todos("SELECT m.fecha, e.codigo_activo, m.titulo, m.detalle FROM v_movimientos m JOIN estaciones e ON e.id = m.estacion_id
            WHERE m.fecha >= $ini AND m.fecha < $fin ORDER BY m.fecha", $p);

        if ($r->query('formato') === 'csv') {
            Audit::registrar('reporte.csv', 'reporte', $dia);
            $o = fopen('php://temp', 'w+');
            fwrite($o, "\xEF\xBB\xBF");
            $seguro = static fn ($v) => preg_match('/^[=+\-@\t\r]/', (string) $v) ? "'" . $v : (string) $v;
            fputcsv($o, ['Fecha', 'Estación', 'Evento', 'Detalle'], ',', '"', '');
            foreach ($eventos as $e) {
                fputcsv($o, [fh($e['fecha'], true), $e['codigo_activo'], $seguro($e['titulo']), $seguro($e['detalle'])], ',', '"', '');
            }
            rewind($o);
            return response((string) stream_get_contents($o), 200, ['Content-Type' => 'text/csv; charset=utf-8', 'Content-Disposition' => 'attachment; filename="reporte-' . $dia . '.csv"']);
        }

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

        Audit::registrar('reporte.ver', 'reporte', $dia);
        return $this->ok([
            'fecha' => $dia, 'fecha_texto' => fd($dia), 'resumen' => array_map('intval', $resumen),
            'riesgo' => $riesgo, 'eventos' => $eventos, 'alertas' => $alertas,
            'generado_por' => $this->usuario($r)['nombre_completo'], 'generado_en' => fh(date('c')),
        ]);
    }
}
