<?php

use App\Http\Controllers\Api\AsistenciaController;
use App\Http\Controllers\Api\Auth\ForgotPasswordController;
use App\Http\Controllers\Api\Auth\ResetPasswordController;
use App\Http\Controllers\Api\ConfirmandoController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\FrontendErrorLogController;
use App\Http\Controllers\Api\GrupoController;
use App\Http\Controllers\Api\GrupoDistributionController;
use App\Http\Controllers\Api\JustificacionController;
use App\Http\Controllers\Api\ParroquiaConfiguracionController;
use App\Http\Controllers\Api\PassportAuthController;
use App\Http\Controllers\Api\PermissionController;
use App\Http\Controllers\Api\ProveedorParroquiaController;
use App\Http\Controllers\Api\RequisitoController;
use App\Http\Controllers\Api\ReunionController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\SacramentoController;
use App\Http\Controllers\Api\TipoApoderadoController;
use App\Http\Controllers\Api\UserController;
use App\Http\Middleware\ParroquiaActiva;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', function (Request $request) {
    return 'api';
});

// Health check público (keep-alive Render) — sin tocar base de datos
Route::get('/health', function () {
    return response()->json(['status' => 'ok'], 200);
});

// Login público (rate limiting estricto para mitigar fuerza bruta)
Route::post('/login', [PassportAuthController::class, 'login'])->middleware('throttle:5,1');

// Recuperar contraseña (público: un usuario sin sesión debe poder solicitarlo/completarlo).
// Rate limiting para mitigar email-bombing y fuerza bruta sobre el token de reseteo.
Route::post('/forgot-password', [ForgotPasswordController::class, 'sendResetLinkEmail'])->middleware('throttle:5,10');
Route::post('/reset-password', [ResetPasswordController::class, 'reset'])->middleware('throttle:6,10');

// Rutas protegidas
Route::middleware(['auth:api', ParroquiaActiva::class])->group(function () {
    Route::get('/get-user', [PassportAuthController::class, 'me']);
    Route::post('/logout', [PassportAuthController::class, 'logout']);
    Route::get('/dashboard/metricas', [DashboardController::class, 'metricasYAlertas']);

    // --- CONFIGURACIÓN DE LA PARROQUIA ---
    Route::get('/parroquia/configuracion', [ParroquiaConfiguracionController::class, 'show']);
    Route::put('/parroquia/configuracion', [ParroquiaConfiguracionController::class, 'update'])->middleware('permission:administrar parroquia');

    // Log de errores JS del frontend (cualquier usuario autenticado puede reportar los suyos).
    // Throttle: sin esto un usuario autenticado puede inundar la tabla frontend_error_logs.
    Route::post('/logs/frontend-error', [FrontendErrorLogController::class, 'store'])->middleware('throttle:20,1');

    // --- IMPORTAR CONFIRMANDOS EXCEL
    Route::get('/confirmandos/exportar', [ConfirmandoController::class, 'exportarExcel']);
    Route::post('/confirmandos/importar', [ConfirmandoController::class, 'importar'])->middleware('permission:crear confirmandos');

    // --- USERS ---
    Route::get('/users', [UserController::class, 'index'])->middleware('permission:ver usuarios');
    Route::post('/users', [UserController::class, 'store'])->middleware('permission:crear usuarios');
    Route::get('/users/{user}', [UserController::class, 'show'])->middleware('permission:ver usuarios');
    Route::put('/users/{user}', [UserController::class, 'update'])->middleware('permission:editar usuarios');
    Route::patch('/users/{user}/estado', [UserController::class, 'estado'])->middleware('permission:editar usuarios');
    Route::delete('/users/{user}', [UserController::class, 'destroy'])->middleware('permission:eliminar usuarios');

    // --- ROLES ---
    // El admin de parroquia gestiona sus roles. Editar el CATÁLOGO DE PERMISOS
    // (abajo) sigue siendo del proveedor: un permiso nuevo requiere código nuevo.
    Route::get('/roles', [RoleController::class, 'index'])->middleware('permission:ver roles');
    Route::get('/roles/{id}', [RoleController::class, 'show'])->middleware('permission:ver roles');
    Route::post('/roles', [RoleController::class, 'store'])->middleware('permission:crear roles');
    Route::put('/roles/{id}', [RoleController::class, 'update'])->middleware('permission:editar roles');
    Route::delete('/roles/{id}', [RoleController::class, 'destroy'])->middleware('permission:eliminar roles');

    // --- PERMISSIONS ---
    // Ver la lista: cualquiera que gestione roles (para los checkboxes del editor).
    // Crear/editar/eliminar el catálogo: solo el proveedor.
    Route::get('/permissions', [PermissionController::class, 'index'])->middleware('permission:ver roles');
    Route::get('/permissions/{id}', [PermissionController::class, 'show'])->middleware('permission:ver roles');
    Route::middleware('permission:administrar plataforma')->group(function () {
        Route::post('/permissions', [PermissionController::class, 'store']);
        Route::put('/permissions/{id}', [PermissionController::class, 'update']);
        Route::delete('/permissions/{id}', [PermissionController::class, 'destroy']);
    });

    // --- PROVEEDOR (plataforma) ---
    Route::middleware('permission:administrar plataforma')->prefix('proveedor')->group(function () {
        Route::get('/parroquias', [ProveedorParroquiaController::class, 'index']);
        Route::post('/parroquias', [ProveedorParroquiaController::class, 'store']);
        Route::patch('/parroquias/{parroquia}', [ProveedorParroquiaController::class, 'update']);
    });

    // --- CONFIRMANDOS ---
    // OJO: las rutas literales van ANTES de /confirmandos/{id} para que {id} no las capture.
    Route::get('/confirmandos/buscar-apoderados', [ConfirmandoController::class, 'buscarApoderados'])->middleware('permission:ver confirmandos');
    Route::get('/confirmandos/{id}/perfil', [ConfirmandoController::class, 'obtenerPerfilCompleto'])->middleware('permission:ver confirmandos');
    Route::put('/confirmandos/{id}/retirar', [ConfirmandoController::class, 'retirar'])->middleware('permission:eliminar confirmandos');
    Route::get('/confirmandos', [ConfirmandoController::class, 'index'])->middleware('permission:ver confirmandos');
    Route::post('/confirmandos', [ConfirmandoController::class, 'store'])->middleware('permission:crear confirmandos');
    Route::get('/confirmandos/{id}', [ConfirmandoController::class, 'show'])->middleware('permission:ver confirmandos');
    Route::put('/confirmandos/{id}', [ConfirmandoController::class, 'update'])->middleware('permission:editar confirmandos');
    Route::delete('/confirmandos/{id}', [ConfirmandoController::class, 'destroy'])->middleware('permission:eliminar confirmandos');

    // --- GRUPOS ---
    Route::get('/grupos', [GrupoController::class, 'index']);
    Route::post('/grupos', [GrupoController::class, 'store'])->middleware('permission:crear grupos');
    Route::get('/grupos/{id}', [GrupoController::class, 'show'])->middleware('permission:ver grupos');
    Route::post('/grupos/{grupo}/sync-catequists', [GrupoController::class, 'syncCatequists'])->middleware('permission:asignar catequista');
    Route::post('/grupos/{grupo}/sync-confirmandos', [GrupoController::class, 'syncConfirmandos'])->middleware('permission:asignar confirmandos');
    Route::put('/grupos/{id}', [GrupoController::class, 'update'])->middleware('permission:editar grupos');
    Route::delete('/grupos/{id}', [GrupoController::class, 'destroy'])->middleware('permission:eliminar grupos');
    Route::get('/grupos/{id}/apoderados', [GrupoController::class, 'getApoderados']);
    Route::post('/grupos/generar-equitativo', [GrupoDistributionController::class, 'generarGruposEquitativos'])->middleware('permission:crear grupos');

    // --- REUNIONES ---
    Route::get('/reuniones/upcoming', [ReunionController::class, 'upcoming']);
    Route::get('/reuniones', [ReunionController::class, 'index'])->middleware('permission:ver cronograma');
    Route::post('/reuniones', [ReunionController::class, 'store'])->middleware('permission:crear cronograma');
    Route::get('/reuniones/{id}', [ReunionController::class, 'show'])->middleware('permission:ver cronograma');
    Route::put('/reuniones/{id}', [ReunionController::class, 'update'])->middleware('permission:editar cronograma');
    Route::delete('/reuniones/{id}', [ReunionController::class, 'destroy'])->middleware('permission:eliminar cronograma');

    // --- ASISTENCIAS ---
    Route::get('/asistencias/matriz', [AsistenciaController::class, 'matrix'])->middleware('permission:ver asistencias');
    Route::get('/reuniones/{id}/asistencias', [AsistenciaController::class, 'index'])->middleware('permission:ver asistencias');
    Route::post('/reuniones/{id}/asistencias', [AsistenciaController::class, 'store'])->middleware('permission:guardar asistencias');

    // --- JUSTIFICACIONES ---
    // 'ver asistencias' lo tienen catequista, coordinador y super-admin. El catequista
    // solo ve/gestiona las justificaciones de los confirmandos de sus grupos (filtrado
    // en el controlador y reforzado por RLS); coordinador/super-admin ven todas.
    Route::prefix('justificaciones')->middleware('permission:ver asistencias')->group(function () {
        Route::get('/', [JustificacionController::class, 'index']);
        Route::post('/acuerdo', [JustificacionController::class, 'registrarAcuerdo']);
        Route::post('/completar', [JustificacionController::class, 'completarJustificacion']);
        Route::put('/{id}/rechazar', [JustificacionController::class, 'rechazarAcuerdo']);
    });

    // --- SACRAMENTOS ---
    Route::get('/sacramentos', [SacramentoController::class, 'index'])->middleware('permission:ver sacramentos');
    Route::post('/sacramentos', [SacramentoController::class, 'store'])->middleware('permission:crear sacramentos');
    Route::get('/sacramentos/{id}', [SacramentoController::class, 'show'])->middleware('permission:ver sacramentos');
    Route::put('/sacramentos/{id}', [SacramentoController::class, 'update'])->middleware('permission:editar sacramentos');
    Route::delete('/sacramentos/{id}', [SacramentoController::class, 'destroy'])->middleware('permission:eliminar sacramentos');

    // --- REQUISITOS ---
    Route::get('/requisitos', [RequisitoController::class, 'index'])->middleware('permission:ver todos los requisitos');
    Route::post('/requisitos', [RequisitoController::class, 'store'])->middleware('permission:crear requisitos');
    Route::get('/requisitos/{id}', [RequisitoController::class, 'show'])->middleware('permission:ver todos los requisitos');
    Route::put('/requisitos/{id}', [RequisitoController::class, 'update'])->middleware('permission:editar requisitos');
    Route::delete('/requisitos/{id}', [RequisitoController::class, 'destroy'])->middleware('permission:eliminar requisitos');

    // --- TIPOS APODERADO ---
    Route::get('/tipos-apoderado', [TipoApoderadoController::class, 'index']);

});

// 404 personalizado global (fuera del grupo auth:api para que aplique también a
// peticiones no autenticadas a endpoints inexistentes)
Route::fallback(function () {
    return response()->json([
        'message' => 'El endpoint de la API no existe.',
    ], 404);
});
