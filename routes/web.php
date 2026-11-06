<?php

use App\Http\Controllers\Api\AuditoriaController;
use App\Http\Controllers\Api\ComponenteController;
use App\Http\Controllers\Api\EstacionController;
use App\Http\Controllers\Api\HistorialController;
use App\Http\Controllers\Api\MantenimientoController;
use App\Http\Controllers\Api\PanelController;
use App\Http\Controllers\Api\PantallaController;
use App\Http\Controllers\Api\PoliticaController;
use App\Http\Controllers\Api\ProveedorController;
use App\Http\Controllers\Api\ReporteController;
use App\Http\Controllers\Api\SesionController;
use App\Http\Controllers\Api\UsuarioController;
use Illuminate\Support\Facades\Route;

Route::pattern('id', '[0-9]+');

/*
 * API del panel que consume React. Va en el grupo «web»: usa la sesión de Laravel y exige el token CSRF
 * (React lo envía en la cabecera X-XSRF-TOKEN). El middleware «alfavia:PERMISO» comprueba sesión, inactividad,
 * cambio de contraseña pendiente y el permiso del rol.
 */
Route::prefix('app-api')->group(function () {
    Route::post('login', [SesionController::class, 'login'])->middleware('throttle:20,1');

    Route::middleware('alfavia:-,sinclave')->group(function () {
        Route::post('logout', [SesionController::class, 'logout']);
        Route::get('yo', [SesionController::class, 'yo']);
        Route::post('tema', [SesionController::class, 'tema']);
    });

    Route::middleware('alfavia:dashboard.ver')->group(function () {
        Route::get('dashboard', [PanelController::class, 'dashboard']);
        Route::get('resumen', [PanelController::class, 'resumen']);
    });
    Route::post('alertas/{id}/atender', [PanelController::class, 'atender'])->middleware('alfavia:alertas.atender');

    Route::middleware('alfavia:estaciones.ver')->group(function () {
        Route::get('estaciones', [EstacionController::class, 'index']);
        Route::get('estaciones/{id}', [EstacionController::class, 'show']);
        Route::get('estaciones/{id}/telemetria', [EstacionController::class, 'telemetria']);
        Route::get('estaciones/{id}/ia', [EstacionController::class, 'ia']);
    });
    Route::middleware('alfavia:estaciones.editar')->group(function () {
        Route::get('formulario-estacion', [EstacionController::class, 'formulario']);
        Route::get('estaciones/{id}/editar', [EstacionController::class, 'editar']);
        Route::post('estaciones', [EstacionController::class, 'store']);
        Route::put('estaciones/{id}', [EstacionController::class, 'update']);
        Route::post('estaciones/{id}/token', [EstacionController::class, 'token']);
    });
    Route::post('estaciones/{id}/componentes', [ComponenteController::class, 'accion'])->middleware('alfavia:componentes.editar');
    Route::post('estaciones/{id}/mantenimientos', [MantenimientoController::class, 'store'])->middleware('alfavia:mantenimiento.registrar');

    Route::get('historial', [HistorialController::class, 'index'])->middleware('alfavia:historial.ver');

    Route::middleware('alfavia:pantallas.ver')->group(function () {
        Route::get('pantallas', [PantallaController::class, 'index']);
        Route::post('pantallas/iniciar', [PantallaController::class, 'iniciar']);
        Route::post('pantallas/{id}/cerrar', [PantallaController::class, 'cerrar']);
        Route::get('pantallas/{id}/frame', [PantallaController::class, 'frame']);
    });

    Route::get('proveedores', [ProveedorController::class, 'index'])->middleware('alfavia:proveedores.ver');
    Route::middleware('alfavia:proveedores.editar')->group(function () {
        Route::post('proveedores', [ProveedorController::class, 'guardar']);
        Route::post('proveedores/{id}/estado', [ProveedorController::class, 'estado']);
    });

    Route::get('politicas', [PoliticaController::class, 'index'])->middleware('alfavia:politicas.ver');
    Route::post('politicas', [PoliticaController::class, 'guardar'])->middleware('alfavia:politicas.editar');

    Route::middleware('alfavia:usuarios.gestionar')->group(function () {
        Route::get('usuarios', [UsuarioController::class, 'index']);
        Route::post('usuarios', [UsuarioController::class, 'crear']);
        Route::post('usuarios/{id}/rol', [UsuarioController::class, 'rol']);
        Route::post('usuarios/{id}/estado', [UsuarioController::class, 'estado']);
        Route::post('usuarios/{id}/desbloquear', [UsuarioController::class, 'desbloquear']);
    });

    Route::get('auditoria', [AuditoriaController::class, 'index'])->middleware('alfavia:auditoria.ver');
    Route::get('reporte', [ReporteController::class, 'diario'])->middleware('alfavia:reportes.ver');
});

// Todo lo demás lo resuelve React (react-router): se devuelve la misma página.
Route::get('/{ruta?}', fn () => view('app'))->where('ruta', '(?!app-api|api/|up$).*');
