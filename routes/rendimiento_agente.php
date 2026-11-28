<?php

use App\Http\Controllers\Api\RendimientoController;
use Illuminate\Support\Facades\Route;

// Lo carga routes/api.php (prefijo /api). Sin límite de 60/min: cada agente envía una lectura cada ~2 s.
Route::post('/agent/rendimiento', [RendimientoController::class, 'recibir'])->withoutMiddleware(['throttle:api']);
