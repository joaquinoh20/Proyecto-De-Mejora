<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Servicios\Db;
use Illuminate\Http\Request;

/** Registro de auditoría: quién hizo qué, cuándo y desde dónde. */
final class AuditoriaController extends BaseApi
{
    public function index(Request $r)
    {
        $q = limpio($r->query('q', ''), 60);
        $accion = limpio($r->query('accion', ''), 60);
        $desde = $this->fecha($r->query('desde', ''));
        $hasta = $this->fecha($r->query('hasta', ''));
        $pag = max(1, (int) $r->query('p', 1));
        $por = 50;

        $w = ['true'];
        $par = [];
        if ($q !== '') { $w[] = '(usuario_nombre ILIKE :q OR entidad_id ILIKE :q2 OR detalle::text ILIKE :q3)'; $like = '%' . addcslashes($q, '%_\\') . '%'; $par += ['q' => $like, 'q2' => $like, 'q3' => $like]; }
        if ($accion !== '') { $w[] = 'accion = :a'; $par['a'] = $accion; }
        if ($desde !== '') { $w[] = 'creado_en >= CAST(:d AS date)'; $par['d'] = $desde; }
        if ($hasta !== '') { $w[] = "creado_en < CAST(:h AS date) + interval '1 day'"; $par['h'] = $hasta; }
        $where = implode(' AND ', $w);
        $total = (int) Db::valor("SELECT count(*) FROM auditoria WHERE $where", $par);
        $filas = Db::todos("SELECT id, usuario_nombre, accion, entidad, entidad_id, detalle, ip, creado_en FROM auditoria WHERE $where ORDER BY creado_en DESC, id DESC LIMIT $por OFFSET " . (($pag - 1) * $por), $par);
        return $this->ok([
            'registros' => $filas, 'total' => $total, 'pagina' => $pag, 'paginas' => max(1, (int) ceil($total / $por)),
            'acciones' => array_column(Db::todos('SELECT DISTINCT accion FROM auditoria ORDER BY accion'), 'accion'),
        ]);
    }
}
