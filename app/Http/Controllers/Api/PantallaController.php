<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Servicios\Auth;
use App\Servicios\Db;
use App\Servicios\Pantallas;
use App\Servicios\Politicas;
use Illuminate\Http\Request;

/**
 * Vista de pantallas bajo demanda: con motivo, tiempo limitado, aviso visible en la PC y auditoría.
 * Solo se guarda el último fotograma de cada estación.
 */
final class PantallaController extends BaseApi
{
    public function index(Request $r)
    {
        $u = $this->usuario($r);
        $uid = (int) $u['id'];
        $mias = Db::todos(
            "SELECT s.id, s.inicio, s.expira_en, s.motivo, e.codigo_activo, e.nombre_host, ub.nombre AS ubicacion FROM sesiones_pantalla s
               JOIN estaciones e ON e.id = s.estacion_id JOIN ubicaciones ub ON ub.id = e.ubicacion_id
              WHERE s.solicitado_por = :u AND s.fin IS NULL AND s.expira_en > now() ORDER BY s.inicio", ['u' => $uid]);
        foreach ($mias as &$m) {
            $m['termina'] = date('H:i', strtotime($m['expira_en']));
        }
        unset($m);
        $estaciones = Db::todos(
            "SELECT e.id, e.codigo_activo, e.nombre_host, e.puesto, ub.nombre AS ubicacion,
                    EXISTS (SELECT 1 FROM sesiones_pantalla s WHERE s.estacion_id = e.id AND s.solicitado_por = :u AND s.fin IS NULL AND s.expira_en > now()) AS mia
               FROM estaciones e JOIN ubicaciones ub ON ub.id = e.ubicacion_id
              WHERE e.activo AND e.estado = 'en_linea' ORDER BY ub.id, e.codigo_activo", ['u' => $uid]);
        $todas = Auth::puede($u, 'auditoria.ver') ? Db::todos(
            "SELECT s.id, s.inicio, s.expira_en, s.motivo, e.codigo_activo, us.nombre_completo FROM sesiones_pantalla s
               JOIN estaciones e ON e.id = s.estacion_id JOIN usuarios us ON us.id = s.solicitado_por
              WHERE s.fin IS NULL AND s.expira_en > now() ORDER BY s.inicio") : [];
        return $this->ok([
            'activas' => $mias, 'estaciones' => $estaciones, 'todas' => $todas,
            'politica' => [
                'max_min' => Politicas::get('pantalla_duracion_max_min'),
                'motivo_obligatorio' => Politicas::get('pantalla_requiere_motivo'),
                'aviso_visible' => Politicas::get('pantalla_aviso_visible'),
            ],
        ]);
    }

    public function iniciar(Request $r)
    {
        [$s, $error] = Pantallas::iniciar((int) $r->input('estacion', 0), (int) $this->usuario($r)['id'], (string) $r->input('motivo', ''), (int) $r->input('minutos', 5));
        if ($error) {
            return $this->fallo($error);
        }
        return $this->ok(['sesion' => (int) $s['id'], 'mensaje' => 'Vista iniciada. La imagen aparece en unos segundos, cuando el agente de la estación la envíe.']);
    }

    public function cerrar(Request $r, int $id)
    {
        $u = $this->usuario($r);
        $s = Pantallas::sesion($id);
        // Solo quien abrió la sesión (o un administrador) puede cerrarla
        if ($s && ((int) $s['solicitado_por'] === (int) $u['id'] || Auth::puede($u, 'usuarios.gestionar'))) {
            Pantallas::cerrar((int) $s['id'], 'cerrada');
        }
        return $this->ok();
    }

    /** Último fotograma de una sesión (solo para quien la abrió, mientras esté vigente). */
    public function frame(Request $r, int $id)
    {
        $u = $this->usuario($r);
        $s = Pantallas::sesion($id);
        $vigente = $s && $s['fin'] === null && strtotime($s['expira_en']) > time() && (int) $s['solicitado_por'] === (int) $u['id'];
        if (!$vigente) {
            return response('', 410);
        }
        $ruta = Pantallas::ruta((int) $s['estacion_id']);
        // No se muestran fotogramas viejos de otra sesión: debe haber uno posterior al inicio de ésta
        if (!is_file($ruta) || filemtime($ruta) < strtotime($s['inicio']) - 2) {
            return response('', 404);
        }
        return response((string) file_get_contents($ruta), 200, ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'no-store']);
    }
}
