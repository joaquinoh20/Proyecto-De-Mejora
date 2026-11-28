<?php
declare(strict_types=1);

namespace App\Servicios;

/**
 * Vista de pantallas bajo demanda.
 *  · Solo se transmite mientras exista una sesión abierta por alguien con permiso «pantallas.ver».
 *  · Cada sesión queda auditada (quién, a qué estación, por qué motivo y cuánto duró).
 *  · Solo se guarda el ÚLTIMO fotograma de cada estación; se borra al cerrar la sesión.
 */
final class Pantallas
{
    public static function directorio(): string
    {
        $d = Config::storage() . '/capturas';
        if (!is_dir($d)) {
            mkdir($d, 0750, true);
        }
        return $d;
    }

    public static function ruta(int $estacionId): string
    {
        return self::directorio() . '/' . $estacionId . '.jpg';
    }

    public static function borrarCuadro(int $estacionId): void
    {
        $r = self::ruta($estacionId);
        if (is_file($r)) {
            @unlink($r);
        }
    }

    /** ¿Hay alguna sesión abierta y vigente para esta estación? */
    public static function hayVista(int $estacionId): bool
    {
        return (bool) Db::valor('SELECT 1 FROM sesiones_pantalla WHERE estacion_id = :e AND fin IS NULL AND expira_en > now() LIMIT 1', ['e' => $estacionId]);
    }

    public static function sesion(int $id): ?array
    {
        return Db::fila(
            'SELECT s.*, e.codigo_activo, e.nombre_host FROM sesiones_pantalla s JOIN estaciones e ON e.id = s.estacion_id WHERE s.id = :id',
            ['id' => $id]
        );
    }

    /** @return array{0:?array,1:?string} [sesión, error] */
    public static function iniciar(int $estacionId, int $usuarioId, string $motivo, int $minutos): array
    {
        $est = Db::fila('SELECT id, codigo_activo, estado, activo FROM estaciones WHERE id = :id', ['id' => $estacionId]);
        if (!$est || !$est['activo']) {
            return [null, 'La estación no existe.'];
        }
        if ($est['estado'] !== 'en_linea') {
            return [null, 'La estación no está en línea; no se puede ver su pantalla.'];
        }
        $motivo = limpio($motivo, 300);
        if (Politicas::get('pantalla_requiere_motivo') && mb_strlen($motivo) < 10) {
            return [null, 'Escribe el motivo de la vista (al menos 10 caracteres). Queda registrado en la auditoría.'];
        }
        $max = Politicas::get('pantalla_duracion_max_min');
        $minutos = max(1, min($minutos, $max));

        $existente = Db::fila('SELECT * FROM sesiones_pantalla WHERE estacion_id = :e AND solicitado_por = :u AND fin IS NULL AND expira_en > now()',
            ['e' => $estacionId, 'u' => $usuarioId]);
        if ($existente) {
            return [$existente, null];
        }
        $s = Db::fila(
            'INSERT INTO sesiones_pantalla (estacion_id, solicitado_por, motivo, expira_en)
             VALUES (:e, :u, :m, now() + make_interval(mins => :min)) RETURNING *',
            ['e' => $estacionId, 'u' => $usuarioId, 'm' => $motivo !== '' ? $motivo : null, 'min' => $minutos]
        );
        Audit::registrar('pantalla.solicitar', 'estacion', $estacionId,
            ['estacion' => $est['codigo_activo'], 'motivo' => $motivo, 'minutos' => $minutos, 'sesion' => $s['id']]);
        return [$s, null];
    }

    public static function cerrar(int $sesionId, string $motivoFin = 'cerrada'): void
    {
        $s = Db::fila("UPDATE sesiones_pantalla SET fin = now(), motivo_fin = :m WHERE id = :id AND fin IS NULL RETURNING estacion_id, solicitado_por",
            ['m' => $motivoFin, 'id' => $sesionId]);
        if ($s) {
            Audit::registrar('pantalla.' . $motivoFin, 'estacion', $s['estacion_id'], ['sesion' => $sesionId]);
            if (!self::hayVista((int) $s['estacion_id'])) {
                self::borrarCuadro((int) $s['estacion_id']);
            }
        }
    }

    /** Guarda el fotograma de forma atómica (escribe a un temporal y renombra). */
    public static function guardarCuadro(int $estacionId, string $bytes): void
    {
        $destino = self::ruta($estacionId);
        $tmp = $destino . '.' . bin2hex(random_bytes(4)) . '.tmp';
        file_put_contents($tmp, $bytes, LOCK_EX);
        rename($tmp, $destino);
    }
}
