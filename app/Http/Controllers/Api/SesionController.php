<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Servicios\Audit;
use App\Servicios\Auth;
use App\Servicios\Db;
use Illuminate\Http\Request;

/** Ingreso, salida, datos de la sesión y tema. La contraseña es el DNI y no se cambia desde el sistema. */
final class SesionController extends BaseApi
{
    public static function resumenUsuario(array $u): array
    {
        return [
            'id' => (int) $u['id'], 'usuario' => $u['usuario'], 'nombre_completo' => $u['nombre_completo'], 'rol' => $u['rol'],
            'permisos' => $u['permisos'], 'tema' => $u['tema'], 'debe_cambiar_clave' => false,
        ];
    }

    public function login(Request $r)
    {
        [$u, $error] = Auth::login($r, (string) $r->input('usuario', ''), (string) $r->input('clave', ''));
        if ($error !== null) {
            return $this->fallo($error, 401);
        }
        $previo = $u['ultimo_ingreso'];   // el valor anterior al ingreso que se acaba de registrar
        $s = $r->session();
        $s->invalidate();                 // sesión nueva: evita fijación de sesión
        $s->regenerateToken();
        $u = Auth::cargar((int) $u['id']);
        $desde = $previo ? max(strtotime($previo), time() - 30 * 86400) : time() - 86400;
        $s->put('uid', (int) $u['id']);
        $s->put('last', time());
        $s->put('since', date('c', $desde));
        $s->put('ua', hash('sha256', (string) $r->userAgent()));
        return $this->ok(['usuario' => self::resumenUsuario($u)]);
    }

    public function logout(Request $r)
    {
        if ($r->session()->get('uid')) {
            Audit::registrar('logout', 'usuario', $r->session()->get('uid'), [], (int) $r->session()->get('uid'));
        }
        $r->session()->invalidate();
        $r->session()->regenerateToken();
        return $this->ok();
    }

    /** Datos del usuario actual (la página lo consulta al abrir). */
    public function yo(Request $r)
    {
        $u = $this->usuario($r);
        return $this->ok(['usuario' => self::resumenUsuario($u), 'desde' => $r->session()->get('since'), 'desde_texto' => fh($r->session()->get('since'))]);
    }

    public function tema(Request $r)
    {
        $t = (string) $r->input('tema', '');
        if (!in_array($t, ['auto', 'claro', 'oscuro'], true)) {
            return $this->fallo('Tema no válido');
        }
        Db::ejecutar('UPDATE usuarios SET tema = :t WHERE id = :id', ['t' => $t, 'id' => (int) $this->usuario($r)['id']]);
        return $this->ok();
    }
}
