<?php
declare(strict_types=1);

namespace App\Servicios;

/** Políticas de seguridad guardadas en la base de datos y editables desde el sistema. */
final class Politicas
{
    private static ?array $cache = null;

    public static function todas(): array
    {
        if (self::$cache === null) {
            self::$cache = [];
            foreach (Db::todos('SELECT * FROM politicas_seguridad ORDER BY categoria, clave') as $f) {
                self::$cache[$f['clave']] = $f;
            }
        }
        return self::$cache;
    }

    public static function get(string $clave): int|float|bool
    {
        $p = self::todas()[$clave] ?? null;
        if ($p === null) {
            throw new \RuntimeException("Política desconocida: $clave");
        }
        return match ($p['tipo']) {
            'int'  => (int) $p['valor'],
            'num'  => (float) $p['valor'],
            default => $p['valor'] === 'true',
        };
    }

    /** Valida y guarda. Devuelve un mensaje de error o null si todo salió bien. */
    public static function guardar(string $clave, string $valor, int $usuarioId): ?string
    {
        $p = self::todas()[$clave] ?? null;
        if ($p === null) {
            return 'Política desconocida';
        }
        if ($p['tipo'] === 'bool') {
            $valor = $valor === 'true' ? 'true' : 'false';
        } else {
            if (!is_numeric($valor)) {
                return 'Debe ser un número';
            }
            $n = (float) $valor;
            if (($p['min_valor'] !== null && $n < (float) $p['min_valor']) || ($p['max_valor'] !== null && $n > (float) $p['max_valor'])) {
                return sprintf('Debe estar entre %s y %s', $p['min_valor'], $p['max_valor']);
            }
            $valor = $p['tipo'] === 'int' ? (string) (int) $n : rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
        }
        if ($valor !== $p['valor']) {
            Db::ejecutar('UPDATE politicas_seguridad SET valor = :v, actualizado_por = :u, actualizado_en = now() WHERE clave = :c',
                ['v' => $valor, 'u' => $usuarioId, 'c' => $clave]);
            Audit::registrar('politica.editar', 'politica', $clave, ['antes' => $p['valor'], 'despues' => $valor]);
            self::$cache = null;
        }
        return null;
    }
}
