<?php
declare(strict_types=1);

namespace App\Servicios;

/** Registro de auditoría: quién hizo qué, cuándo y desde dónde. */
final class Audit
{
    public static function registrar(string $accion, ?string $entidad = null, string|int|null $entidadId = null,
                                     array $detalle = [], ?int $usuarioId = null, ?string $usuarioNombre = null): void
    {
        try {
            $req = app()->bound('request') ? request() : null;
            $u = $req?->attributes->get('usuario');
            $usuarioId ??= $u ? (int) $u['id'] : null;
            $usuarioNombre ??= $u['nombre_completo'] ?? null;
            $ip = $req?->ip();
            if ($ip !== null && filter_var($ip, FILTER_VALIDATE_IP) === false) {
                $ip = null;
            }
            Db::ejecutar(
                'INSERT INTO auditoria (usuario_id, usuario_nombre, accion, entidad, entidad_id, detalle, ip, agente_http)
                 VALUES (:u, :n, :a, :e, :i, CAST(:d AS jsonb), CAST(:ip AS inet), :ua)',
                [
                    'u' => $usuarioId,
                    'n' => $usuarioNombre !== null ? mb_substr($usuarioNombre, 0, 120) : null,
                    'a' => $accion,
                    'e' => $entidad,
                    'i' => $entidadId !== null ? (string) $entidadId : null,
                    'd' => json_encode($detalle, JSON_UNESCAPED_UNICODE),
                    'ip' => $ip,
                    'ua' => mb_substr((string) $req?->userAgent(), 0, 200) ?: null,
                ]
            );
        } catch (\Throwable $e) {
            error_log('[AlfaVia] No se pudo registrar auditoría: ' . $e->getMessage());
        }
    }
}
