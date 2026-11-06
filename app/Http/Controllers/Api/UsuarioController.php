<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Servicios\Audit;
use App\Servicios\Auth;
use App\Servicios\Db;
use Illuminate\Http\Request;

/** Administración de usuarios (solo Administrador del Sistema). La contraseña de cada usuario es su DNI y no se modifica. */
final class UsuarioController extends BaseApi
{
    public function index(Request $r)
    {
        return $this->ok([
            'usuarios' => Db::todos('SELECT u.id, u.usuario, u.nombre_completo, u.correo, u.rol_id, u.activo, u.bloqueado_hasta, u.ultimo_ingreso, r.nombre AS rol
                                       FROM usuarios u JOIN roles r ON r.id = u.rol_id ORDER BY u.activo DESC, u.usuario'),
            'roles' => Db::todos('SELECT id, nombre FROM roles ORDER BY id'),
            'yo' => (int) $this->usuario($r)['id'],
        ]);
    }

    public function crear(Request $r)
    {
        $nombre = limpio($r->input('nombre', ''), 60);
        $apellido = limpio($r->input('apellido', ''), 60);
        $dni = trim((string) $r->input('dni', ''));
        $correo = limpio($r->input('correo', ''), 120);
        $rol = (int) $r->input('rol_id', 0);
        $roles = array_map(static fn ($x) => (int) $x['id'], Db::todos('SELECT id FROM roles'));
        $usuario = Auth::nombreUsuario($nombre, $apellido);
        $err = null;
        if ($usuario === null) { $err = 'Escribe el nombre y el apellido (solo letras).'; }
        elseif (($e = Auth::validarDni($dni)) !== null) { $err = $e; }
        elseif ($correo !== '' && !filter_var($correo, FILTER_VALIDATE_EMAIL)) { $err = 'El correo no es válido.'; }
        elseif (!in_array($rol, $roles, true)) { $err = 'Elige un rol.'; }
        elseif (Db::valor('SELECT 1 FROM usuarios WHERE lower(usuario) = :u', ['u' => $usuario])) { $err = "Ya existe el usuario $usuario."; }
        if ($err) {
            return $this->fallo($err);
        }
        $nuevo = (int) Db::valor('INSERT INTO usuarios (usuario, nombre_completo, correo, clave_hash, rol_id, debe_cambiar_clave) VALUES (:u,:n,:c,:h,:r,false) RETURNING id',
            ['u' => $usuario, 'n' => trim("$nombre $apellido"), 'c' => $correo ?: null, 'h' => Auth::hash($dni), 'r' => $rol]);
        Audit::registrar('usuario.crear', 'usuario', $nuevo, ['usuario' => $usuario, 'rol_id' => $rol]);
        return $this->ok(['creado' => ['usuario' => $usuario]]);
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
}
