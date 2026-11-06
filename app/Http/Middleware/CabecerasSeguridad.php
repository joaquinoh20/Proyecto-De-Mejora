<?php
declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Cabeceras de seguridad. La política CSP estricta se activa cuando el frontend está compilado (npm run build). */
final class CabecerasSeguridad
{
    public function handle(Request $r, Closure $next): Response
    {
        $resp = $next($r);
        $resp->headers->set('X-Content-Type-Options', 'nosniff');
        $resp->headers->set('X-Frame-Options', 'DENY');
        $resp->headers->set('Referrer-Policy', 'same-origin');
        $resp->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        if (str_starts_with($r->path(), 'app-api') || str_starts_with($r->path(), 'api/')) {
            $resp->headers->set('Cache-Control', 'no-store');
        }
        // Con «npm run dev» existe public/hot y Vite sirve scripts desde otro puerto: no se aplica CSP.
        if (!file_exists(public_path('hot'))) {
            $resp->headers->set('Content-Security-Policy', "default-src 'self'; img-src 'self' data: blob:; style-src 'self'; script-src 'self'; "
                . "connect-src 'self'; frame-ancestors 'none'; form-action 'self'; base-uri 'self'; object-src 'none'");
        }
        if ($r->isSecure()) {
            $resp->headers->set('Strict-Transport-Security', 'max-age=31536000');
        }
        return $resp;
    }
}
