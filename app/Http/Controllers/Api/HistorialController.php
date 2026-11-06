<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Servicios\Db;
use Illuminate\Http\Request;

/** Historial de movimientos: estaciones que se desconectan, dispositivos y cambios de componentes. */
final class HistorialController extends BaseApi
{
    public function index(Request $r)
    {
        $cat = (string) $r->query('cat', '');
        $est = (int) $r->query('estacion', 0);
        $desde = $this->fecha($r->query('desde', ''));
        $hasta = $this->fecha($r->query('hasta', ''));
        $pag = max(1, (int) $r->query('p', 1));
        $porPag = 40;

        $where = ['TRUE'];
        $p = [];
        if (in_array($cat, ['conexion', 'dispositivo', 'componente'], true)) { $where[] = 'v.categoria = :cat'; $p['cat'] = $cat; }
        if ($est > 0) { $where[] = 'v.estacion_id = :est'; $p['est'] = $est; }
        if ($desde !== '') { $where[] = 'v.fecha >= CAST(:desde AS date)'; $p['desde'] = $desde; }
        if ($hasta !== '') { $where[] = "v.fecha < CAST(:hasta AS date) + interval '1 day'"; $p['hasta'] = $hasta; }
        $cond = implode(' AND ', $where);

        if ($r->query('csv') !== null) {
            $filas = Db::todos("SELECT v.fecha, e.codigo_activo, v.categoria, v.titulo, v.detalle FROM v_movimientos v JOIN estaciones e ON e.id = v.estacion_id WHERE $cond ORDER BY v.fecha DESC LIMIT 5000", $p);
            $o = fopen('php://temp', 'w+');
            fwrite($o, "\xEF\xBB\xBF");   // para que Excel respete los acentos
            fputcsv($o, ['Fecha', 'Estación', 'Categoría', 'Movimiento', 'Detalle'], ',', '"', '');
            // Evita que Excel interprete un texto como fórmula
            $seguro = static fn (string $s): string => preg_match('/^[=+\-@\t\r]/', $s) ? "'" . $s : $s;
            foreach ($filas as $f) {
                fputcsv($o, [fh($f['fecha']), $f['codigo_activo'], $f['categoria'], $seguro($f['titulo']), $seguro((string) $f['detalle'])], ',', '"', '');
            }
            rewind($o);
            $csv = stream_get_contents($o);
            return response($csv, 200, ['Content-Type' => 'text/csv; charset=utf-8', 'Content-Disposition' => 'attachment; filename="historial_movimientos.csv"']);
        }

        $total = (int) Db::valor("SELECT count(*) FROM v_movimientos v WHERE $cond", $p);
        $filas = Db::todos("SELECT v.*, e.codigo_activo FROM v_movimientos v JOIN estaciones e ON e.id = v.estacion_id WHERE $cond
                            ORDER BY v.fecha DESC LIMIT $porPag OFFSET " . (($pag - 1) * $porPag), $p);
        return $this->ok([
            'movimientos' => $filas, 'total' => $total, 'pagina' => $pag, 'paginas' => max(1, (int) ceil($total / $porPag)),
            'estaciones' => Db::todos('SELECT id, codigo_activo FROM estaciones WHERE activo ORDER BY codigo_activo'),
        ]);
    }
}
