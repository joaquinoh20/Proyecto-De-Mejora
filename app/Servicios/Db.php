<?php
declare(strict_types=1);

namespace App\Servicios;

use Illuminate\Support\Facades\DB as Conexion;
use PDO;
use PDOStatement;

/**
 * Acceso a PostgreSQL con la conexión de Laravel (config/database.php).
 * Todas las consultas usan parámetros con nombre: nunca se concatenan datos en el SQL.
 */
final class Db
{
    private static ?PDO $configurado = null;

    public static function pdo(): PDO
    {
        $pdo = Conexion::connection()->getPdo();
        if (self::$configurado !== $pdo) {          // una sola vez por conexión
            $pdo->exec("SET TIME ZONE 'America/Lima'");
            self::$configurado = $pdo;
        }
        return $pdo;
    }

    private static function preparar(string $sql, array $params): PDOStatement
    {
        $st = self::pdo()->prepare($sql);
        $st->setFetchMode(PDO::FETCH_ASSOC);
        foreach ($params as $k => $v) {
            $nombre = is_int($k) ? $k + 1 : ':' . ltrim((string) $k, ':');
            $tipo = match (true) {
                is_bool($v) => PDO::PARAM_BOOL,
                is_int($v)  => PDO::PARAM_INT,
                $v === null => PDO::PARAM_NULL,
                default     => PDO::PARAM_STR,
            };
            $st->bindValue($nombre, $v, $tipo);
        }
        $st->execute();
        return $st;
    }

    public static function todos(string $sql, array $params = []): array
    {
        return self::preparar($sql, $params)->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function fila(string $sql, array $params = []): ?array
    {
        $r = self::preparar($sql, $params)->fetch(PDO::FETCH_ASSOC);
        return $r === false ? null : $r;
    }

    public static function valor(string $sql, array $params = []): mixed
    {
        $r = self::preparar($sql, $params)->fetch(PDO::FETCH_NUM);
        return $r === false ? null : $r[0];
    }

    /** Ejecuta INSERT/UPDATE/DELETE y devuelve las filas afectadas. */
    public static function ejecutar(string $sql, array $params = []): int
    {
        return self::preparar($sql, $params)->rowCount();
    }

    public static function transaccion(callable $fn): mixed
    {
        return Conexion::transaction($fn);
    }
}
