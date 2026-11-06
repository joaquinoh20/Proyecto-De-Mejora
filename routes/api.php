<?php

use App\Http\Controllers\Api\AgenteController;
use Illuminate\Support\Facades\Route;

// Agentes instalados en las PCs. Se autentican con el token de la estación (cabecera X-Agent-Token).
// Esta ruta es «api» (sin sesión ni CSRF), por eso queda en /api/agent/...
Route::post('agent/latido', [AgenteController::class, 'latido']);
Route::post('agent/pantalla', [AgenteController::class, 'pantalla']);
