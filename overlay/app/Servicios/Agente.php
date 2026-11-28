<?php
declare(strict_types=1);

namespace App\Servicios;

use Illuminate\Http\Request;

/** Tokens de los agentes instalados en las PCs (un token por estación, guardado como hash SHA-256). */
final class Agente
{
    /** Devuelve la estación dueña del token, o null si falta o no es válido. */
    public static function estacion(Request $r): ?array
    {
        $token = (string) $r->header('X-Agent-Token', '');
        if ($token === '' && preg_match('/^Bearer\s+(\S+)$/i', (string) $r->header('Authorization', ''), $m)) {
            $token = $m[1];
        }
        if ($token === '' || strlen($token) > 200) {
            return null;
        }
        $est = Db::fila('SELECT * FROM estaciones WHERE agente_token_hash = :h AND activo', ['h' => hash('sha256', $token)]);
        if (!$est) {
            error_log('[AlfaVia] Token de agente inválido desde ' . $r->ip());
        }
        return $est;
    }

    /** Crea un token nuevo, guarda su hash y lo devuelve (solo se puede ver esta vez). */
    public static function generarToken(int $estacionId): string
    {
        $token = bin2hex(random_bytes(24));
        Db::ejecutar('UPDATE estaciones SET agente_token_hash = :h, agente_token_creado = now() WHERE id = :id',
            ['h' => hash('sha256', $token), 'id' => $estacionId]);
        return $token;
    }
}
