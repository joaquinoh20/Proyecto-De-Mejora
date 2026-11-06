<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Servicios\Auth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Utilidades comunes de los controladores de la API del panel. */
abstract class BaseApi
{
    protected function usuario(Request $r): array
    {
        return $r->attributes->get('usuario');
    }

    protected function puede(Request $r, string $permiso): bool
    {
        return Auth::puede($this->usuario($r), $permiso);
    }

    protected function ok(array $datos = []): JsonResponse
    {
        return response()->json(['ok' => true] + $datos);
    }

    protected function fallo(string $mensaje, int $codigo = 422, array $extra = []): JsonResponse
    {
        return response()->json(['ok' => false, 'error' => $mensaje] + $extra, $codigo);
    }

    /** Fecha AAAA-MM-DD válida o ''. */
    protected function fecha(mixed $v): string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $v) ? (string) $v : '';
    }
}
