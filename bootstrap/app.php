<?php

use App\Http\Middleware\CabecerasSeguridad;
use App\Http\Middleware\SesionAlfavia;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

// Funciones de formato (limpio, fh, hace…) usadas por los servicios
require_once __DIR__ . '/../app/helpers.php';

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',      // página de React y API del panel (/app-api, con sesión y CSRF)
        api: __DIR__ . '/../routes/api.php',      // agentes de las PCs (/api/agent, con token, sin sesión)
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias(['alfavia' => SesionAlfavia::class]);
        $middleware->append(CabecerasSeguridad::class);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // La API siempre responde JSON, también en errores (419 CSRF, 404, 500…)
        $exceptions->shouldRenderJsonWhen(fn (Request $request, Throwable $e) => $request->is('app-api/*', 'api/*') || $request->expectsJson());
    })->create();
