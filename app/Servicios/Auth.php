<?php
declare(strict_types=1);

namespace App\Servicios;

use Illuminate\Http\Request;

/** Inicio de sesión, política de contraseñas y permisos por rol (sesión de Laravel). */
final class Auth
{
    // Hash de relleno: se verifica aunque el usuario no exista, para que el tiempo de respuesta no delate usuarios válidos
    private const HASH_RELLENO = '$2y$12$XdYh2xitDotfHRYGc.AT0.sHDqDVc9aSLhcrGhnz3Db4OVTwjDZ7W';

    /** Usuario con su rol y permisos, o null si no existe o está inactivo. */
    public static function cargar(int $id): ?array
    {
        $u = Db::fila('SELECT u.*, r.nombre AS rol FROM usuarios u JOIN roles r ON r.id = u.rol_id WHERE u.id = :id', ['id' => $id]);
        if (!$u || !$u['activo']) {
            return null;
        }
        $u['permisos'] = array_column(Db::todos(
            'SELECT p.codigo FROM roles_permisos rp JOIN permisos p ON p.id = rp.permiso_id WHERE rp.rol_id = :r',
            ['r' => (int) $u['rol_id']]
        ), 'codigo');
        return $u;
    }

    public static function puede(array $u, string $permiso): bool
    {
        return in_array($permiso, $u['permisos'], true);
    }

    /** La contraseña es el DNI y no se modifica ni vence: nunca se exige cambiarla. */
    public static function claveVencida(array $u): bool
    {
        return false;
    }

    /** Devuelve [usuario, error]. Si hay error, el usuario es null. */
    public static function login(Request $r, string $usuario, string $clave): array
    {
        $ip = $r->ip();
        if ($ip && filter_var($ip, FILTER_VALIDATE_IP)) {
            $fallos = (int) Db::valor(
                "SELECT count(*) FROM auditoria WHERE accion = 'login.fallido' AND ip = CAST(:ip AS inet) AND creado_en > now() - interval '10 minutes'",
                ['ip' => $ip]
            );
            if ($fallos >= 20) {
                Audit::registrar('login.limite_ip', 'usuario', null, ['usuario' => limpio($usuario, 60)]);
                return [null, 'Demasiados intentos desde este equipo. Espera unos minutos.'];
            }
        }

        $u = Db::fila('SELECT * FROM usuarios WHERE lower(usuario) = lower(:u)', ['u' => limpio($usuario, 60)]);
        $correcta = password_verify($clave, $u['clave_hash'] ?? self::HASH_RELLENO) && $u !== null;

        if ($u !== null && $u['bloqueado_hasta'] && strtotime($u['bloqueado_hasta']) > time()) {
            Audit::registrar('login.bloqueado', 'usuario', $u['id'], [], (int) $u['id'], $u['usuario']);
            $min = max(1, (int) ceil((strtotime($u['bloqueado_hasta']) - time()) / 60));
            return [null, "La cuenta está bloqueada por intentos fallidos. Intenta de nuevo en $min min o pide al administrador que la desbloquee."];
        }

        if (!$correcta || !$u['activo']) {
            if ($u !== null) {
                $n = (int) $u['intentos_fallidos'] + 1;
                if ($n >= Politicas::get('intentos_max')) {
                    Db::ejecutar('UPDATE usuarios SET intentos_fallidos = 0, bloqueado_hasta = now() + make_interval(mins => :m) WHERE id = :id',
                        ['m' => Politicas::get('bloqueo_minutos'), 'id' => (int) $u['id']]);
                    Audit::registrar('login.bloqueo', 'usuario', $u['id'], ['intentos' => $n], (int) $u['id'], $u['usuario']);
                } else {
                    Db::ejecutar('UPDATE usuarios SET intentos_fallidos = :n WHERE id = :id', ['n' => $n, 'id' => (int) $u['id']]);
                }
            }
            Audit::registrar('login.fallido', 'usuario', $u['id'] ?? null, ['usuario' => limpio($usuario, 60)], $u ? (int) $u['id'] : null, $u['usuario'] ?? null);
            return [null, 'Usuario o contraseña incorrectos.'];
        }

        Db::ejecutar('UPDATE usuarios SET intentos_fallidos = 0, bloqueado_hasta = NULL, ultimo_ingreso = now() WHERE id = :id', ['id' => (int) $u['id']]);
        Audit::registrar('login.ok', 'usuario', $u['id'], [], (int) $u['id'], $u['nombre_completo']);
        return [$u, null];
    }

    /** La contraseña es el número de DNI: exactamente 8 dígitos. Devuelve un mensaje de error o null. */
    public static function validarDni(string $dni): ?string
    {
        return preg_match('/^\d{8}$/', $dni) ? null : 'El DNI debe tener exactamente 8 dígitos.';
    }

    /** Quita tildes y deja solo letras a-z en minúscula (primera palabra). */
    private static function limpiarNombre(string $s): string
    {
        $s = trim($s);
        $s = explode(' ', preg_replace('/\s+/', ' ', $s))[0] ?? '';
        $s = strtr(mb_strtolower($s, 'UTF-8'), [
            'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a', 'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
            'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i', 'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o',
            'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u', 'ñ' => 'n',
        ]);
        return preg_replace('/[^a-z]/', '', $s);
    }

    /** Usuario con el formato nombre.apellido#alfavia (primer nombre y primer apellido). Null si no se puede armar. */
    public static function nombreUsuario(string $nombre, string $apellido): ?string
    {
        $n = self::limpiarNombre($nombre);
        $a = self::limpiarNombre($apellido);
        return ($n === '' || $a === '') ? null : "$n.$a#alfavia";
    }

    /** argon2id si este PHP lo soporta; si no, bcrypt (ambos los verifica password_verify). */
    public static function hash(string $clave): string
    {
        return password_hash($clave, defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT);
    }
}
