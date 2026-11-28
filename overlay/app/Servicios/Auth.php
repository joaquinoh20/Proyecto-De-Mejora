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

    public static function claveVencida(array $u): bool
    {
        if ($u['debe_cambiar_clave']) {
            return true;
        }
        $dias = Politicas::get('clave_vigencia_dias');
        return strtotime($u['clave_cambiada_en']) < time() - $dias * 86400;
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

    /** Aplica la política de contraseñas. Devuelve un mensaje de error o null. */
    public static function validarClave(string $c, string $usuario = ''): ?string
    {
        $min = Politicas::get('clave_longitud_min');
        if (mb_strlen($c) < $min) {
            return "La contraseña debe tener al menos $min caracteres.";
        }
        if (mb_strlen($c) > 128) {
            return 'La contraseña es demasiado larga (máximo 128).';
        }
        if (Politicas::get('clave_complejidad')) {
            if (!preg_match('/[a-z]/u', $c) || !preg_match('/[A-Z]/u', $c) || !preg_match('/\d/', $c) || !preg_match('/[^\p{L}\d]/u', $c)) {
                return 'Debe incluir mayúscula, minúscula, número y un símbolo.';
            }
        }
        if ($usuario !== '' && stripos($c, $usuario) !== false) {
            return 'La contraseña no debe contener tu nombre de usuario.';
        }
        return null;
    }

    public static function cambiarClave(int $usuarioId, string $nueva, bool $forzarCambioDespues = false): void
    {
        Db::ejecutar(
            'UPDATE usuarios SET clave_hash = :h, clave_cambiada_en = now(), debe_cambiar_clave = :f, intentos_fallidos = 0, bloqueado_hasta = NULL WHERE id = :id',
            ['h' => self::hash($nueva), 'f' => $forzarCambioDespues, 'id' => $usuarioId]
        );
    }

    /** argon2id si este PHP lo soporta; si no, bcrypt (ambos los verifica password_verify). */
    public static function hash(string $clave): string
    {
        return password_hash($clave, defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT);
    }

    public static function claveTemporal(): string
    {
        $a = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        $b = 'abcdefghijkmnopqrstuvwxyz';
        $n = '23456789';
        $s = '#$%&*+-=?@';
        $pick = static fn (string $set): string => $set[random_int(0, strlen($set) - 1)];
        $c = [$pick($a), $pick($b), $pick($n), $pick($s)];
        $todo = $a . $b . $n;
        for ($i = 0; $i < 10; $i++) {
            $c[] = $pick($todo);
        }
        for ($i = count($c) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$c[$i], $c[$j]] = [$c[$j], $c[$i]];
        }
        return implode('', $c);
    }
}
