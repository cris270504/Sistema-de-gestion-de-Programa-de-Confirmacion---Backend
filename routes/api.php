<?php

use App\Http\Controllers\Api\PassportAuthController;
use App\Http\Controllers\Api\PermissionController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\ConfirmandoController;
use App\Http\Controllers\Api\GrupoController;
use App\Http\Controllers\Api\ReunionController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', function (Request $request) {
    return 'api';
});

// Login público
Route::post('/login', [PassportAuthController::class, 'login']);

// Rutas protegidas
Route::middleware('auth:api')->group(function () {
    Route::get('/get-user', [PassportAuthController::class, 'me']);
    Route::post('/logout', [PassportAuthController::class, 'logout']);

    // --- USERS ---
    Route::get('/users', [UserController::class, 'index'])->middleware('permission:ver usuarios');
    Route::post('/users', [UserController::class, 'store'])->middleware('permission:crear usuarios');
    Route::get('/users/{user}', [UserController::class, 'show'])->middleware('permission:ver usuarios');
    Route::put('/users/{user}', [UserController::class, 'update'])->middleware('permission:editar usuarios');
    Route::delete('/users/{user}', [UserController::class, 'destroy'])->middleware('permission:eliminar usuarios');

    // --- ROLES ---
    Route::get('/roles', [RoleController::class, 'index'])->middleware('permission:ver roles');
    Route::post('/roles', [RoleController::class, 'store'])->middleware('permission:crear roles');
    Route::get('/roles/{id}', [RoleController::class, 'show'])->middleware('permission:ver roles');
    Route::put('/roles/{id}', [RoleController::class, 'update'])->middleware('permission:editar roles');
    Route::delete('/roles/{id}', [RoleController::class, 'destroy'])->middleware('permission:eliminar roles');

    // --- PERMISSIONS ---
    Route::get('/permissions', [PermissionController::class, 'index'])->middleware('permission:ver permisos');
    Route::post('/permissions', [PermissionController::class, 'store'])->middleware('permission:crear permisos');
    Route::get('/permissions/{id}', [PermissionController::class, 'show'])->middleware('permission:ver permisos');
    Route::put('/permissions/{id}', [PermissionController::class, 'update'])->middleware('permission:editar permisos');
    Route::delete('/permissions/{id}', [PermissionController::class, 'destroy'])->middleware('permission:eliminar permisos');

    // --- CONFIRMANDOS ---
    Route::get('/confirmandos',[ConfirmandoController::class,'index'])->middleware('permission:ver confirmandos');
    Route::post('/confirmandos', [ConfirmandoController::class, 'store'])->middleware('permission:crear confirmandos');
    Route::get('/confirmandos/{id}', [ConfirmandoController::class, 'show'])->middleware('permission:ver confirmandos');
    Route::put('/confirmandos/{id}', [ConfirmandoController::class, 'update'])->middleware('permission:editar confirmandos');
    Route::delete('/confirmandos/{id}', [ConfirmandoController::class, 'destroy'])->middleware('permission:eliminar confirmandos');

    // --- GRUPOS ---
    Route::get('/grupos',[GrupoController::class,'index']);
    Route::post('/grupos', [GrupoController::class, 'store'])->middleware('permission:crear grupos');
    Route::get('/grupos/{id}', [GrupoController::class, 'show'])->middleware('permission:ver grupos');
    Route::post('/grupos/{grupo}/sync-catequists',   [GrupoController::class, 'syncCatequists'])->middleware('permission:asignar catequista');
    Route::post('/grupos/{grupo}/sync-confirmandos', [GrupoController::class, 'syncConfirmandos'])->middleware('permission:asignar confirmandos');
    Route::put('/grupos/{id}', [GrupoController::class, 'update'])->middleware('permission:editar grupos');
    Route::delete('/grupos/{id}', [GrupoController::class, 'destroy'])->middleware('permission:eliminar grupos');

    // --- REUNIONES ---
    Route::get('/reuniones', [ReunionController::class, 'index'])->middleware('permission:ver cronograma');
    Route::get('/reuniones/upcoming', [ReunionController::class, 'upcoming']);

});