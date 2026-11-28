<?php
declare(strict_types=1);

namespace App\Http\Middleware;

use App\Servicios\Audit;
use App\Servicios\Auth;
use App\Servicios\Politicas;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Protege la API del panel: sesión válida, mismo navegador, cierre por inactividad,
 * usuario activo, cambio de contraseña pendiente y permiso del rol.
 *
 * Uso en rutas:  ->middleware('alfavia:estaciones.ver')
 *                ->middleware('alfavia:-,sinclave')   (sin permiso; deja pasar aunque deba cambiar la clave)
 * Las consultas automáticas (refresco del panel, fotogramas) envían X-Actividad: 0 (o ?act=0)
 * para no alargar la sesión: así el cierre por inactividad funciona aunque la pestaña quede abierta.
 */
final class SesionAlfavia
{
    public function handle(Request $r, Closure $next, string $permiso = '-', string $opcion = ''): Response
    {
        $s = $r->session();
        if (!$s->get('uid')) {
            return response()->json(['ok' => false, 'error' => 'No autenticado', 'motivo' => 'sin_sesion'], 401);
        }
        if (!hash_equals((string) $s->get('ua', ''), hash('sha256', (string) $r->userAgent()))) {
            return $this->cerrar($r, 'invalida');
        }
        if (time() - (int) $s->get('last', 0) > Politicas::get('sesion_inactividad_min') * 60) {
            return $this->cerrar($r, 'expirada');
        }
        $u = Auth::cargar((int) $s->get('uid'));
        if ($u === null) {
            return $this->cerrar($r, 'invalida');
        }
        if ($r->header('X-Actividad') !== '0' && $r->query('act') !== '0') {
            $s->put('last', time());
        }
        $r->attributes->set('usuario', $u);

        if ($opcion !== 'sinclave' && Auth::claveVencida($u)) {
            return response()->json(['ok' => false, 'error' => 'Debes cambiar tu contraseña', 'motivo' => 'cambio_clave'], 403);
        }
        if ($permiso !== '-' && !Auth::puede($u, $permiso)) {
            Audit::registrar('acceso.denegado', 'permiso', $permiso, ['ruta' => $r->path()]);
            return response()->json(['ok' => false, 'error' => 'Tu rol no tiene permiso para esto', 'motivo' => 'sin_permiso'], 403);
        }
        return $next($r);
    }

    private function cerrar(Request $r, string $motivo): Response
    {
        $r->session()->invalidate();
        $r->session()->regenerateToken();
        return response()->json(['ok' => false, 'error' => 'Sesión no válida', 'motivo' => $motivo], 401);
    }
}
