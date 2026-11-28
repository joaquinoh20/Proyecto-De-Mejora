<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Servicios\Audit;
use App\Servicios\Auth;
use App\Servicios\Db;
use Illuminate\Http\Request;

/** Administración de usuarios (solo Administrador del Sistema). Las claves temporales se muestran una sola vez. */
final class UsuarioController extends BaseApi
{
    public function index(Request $r)
    {
        return $this->ok([
            'usuarios' => Db::todos('SELECT u.id, u.usuario, u.nombre_completo, u.correo, u.rol_id, u.activo, u.bloqueado_hasta, u.debe_cambiar_clave, u.ultimo_ingreso, r.nombre AS rol
                                       FROM usuarios u JOIN roles r ON r.id = u.rol_id ORDER BY u.activo DESC, u.usuario'),
            'roles' => Db::todos('SELECT id, nombre FROM roles ORDER BY id'),
            'yo' => (int) $this->usuario($r)['id'],
        ]);
    }

    public function crear(Request $r)
    {
        $usuario = strtolower(limpio($r->input('usuario', ''), 40));
        $nombre = limpio($r->input('nombre_completo', ''), 120);
        $correo = limpio($r->input('correo', ''), 120);
        $rol = (int) $r->input('rol_id', 0);
        $roles = array_map(static fn ($x) => (int) $x['id'], Db::todos('SELECT id FROM roles'));
        $err = null;
        if (!preg_match('/^[a-z0-9._-]{3,40}$/', $usuario)) { $err = 'El usuario debe tener 3 a 40 caracteres: letras, números, punto, guion.'; }
        elseif ($nombre === '') { $err = 'Escribe el nombre completo.'; }
        elseif ($correo !== '' && !filter_var($correo, FILTER_VALIDATE_EMAIL)) { $err = 'El correo no es válido.'; }
        elseif (!in_array($rol, $roles, true)) { $err = 'Elige un rol.'; }
        elseif (Db::valor('SELECT 1 FROM usuarios WHERE lower(usuario) = :u', ['u' => $usuario])) { $err = 'Ese usuario ya existe.'; }
        if ($err) {
            return $this->fallo($err);
        }
        $temporal = Auth::claveTemporal();
        $nuevo = (int) Db::valor('INSERT INTO usuarios (usuario, nombre_completo, correo, clave_hash, rol_id, debe_cambiar_clave) VALUES (:u,:n,:c,:h,:r,true) RETURNING id',
            ['u' => $usuario, 'n' => $nombre, 'c' => $correo ?: null, 'h' => Auth::hash($temporal), 'r' => $rol]);
        Audit::registrar('usuario.crear', 'usuario', $nuevo, ['usuario' => $usuario, 'rol_id' => $rol]);
        return $this->ok(['temporal' => ['usuario' => $usuario, 'clave' => $temporal]]);
    }

    public function rol(Request $r, int $id)
    {
        if ($id === (int) $this->usuario($r)['id']) {
            return $this->fallo('No puedes cambiar tu propio rol.');
        }
        $rol = (int) $r->input('rol_id', 0);
        if (!Db::valor('SELECT 1 FROM roles WHERE id = :id', ['id' => $rol])) {
            return $this->fallo('Rol no válido.');
        }
        Db::ejecutar('UPDATE usuarios SET rol_id = :r WHERE id = :id', ['r' => $rol, 'id' => $id]);
        Audit::registrar('usuario.rol', 'usuario', $id, ['rol_id' => $rol]);
        return $this->ok();
    }

    public function estado(Request $r, int $id)
    {
        if ($id === (int) $this->usuario($r)['id']) {
            return $this->fallo('No puedes desactivar tu propia cuenta.');
        }
        $n = Db::fila('UPDATE usuarios SET activo = NOT activo WHERE id = :id RETURNING activo', ['id' => $id]);
        if (!$n) {
            return $this->fallo('No existe ese usuario.', 404);
        }
        Audit::registrar($n['activo'] ? 'usuario.activar' : 'usuario.desactivar', 'usuario', $id);
        return $this->ok();
    }

    public function desbloquear(Request $r, int $id)
    {
        Db::ejecutar('UPDATE usuarios SET intentos_fallidos = 0, bloqueado_hasta = NULL WHERE id = :id', ['id' => $id]);
        Audit::registrar('usuario.desbloquear', 'usuario', $id);
        return $this->ok();
    }

    public function reiniciar(Request $r, int $id)
    {
        if ($id === (int) $this->usuario($r)['id']) {
            return $this->fallo('Cambia tu propia contraseña desde Mi cuenta.');
        }
        $f = Db::fila('SELECT usuario FROM usuarios WHERE id = :id', ['id' => $id]);
        if (!$f) {
            return $this->fallo('No existe ese usuario.', 404);
        }
        $t = Auth::claveTemporal();
        Auth::cambiarClave($id, $t, true);
        Audit::registrar('usuario.reiniciar_clave', 'usuario', $id);
        return $this->ok(['temporal' => ['usuario' => $f['usuario'], 'clave' => $t]]);
    }
}
