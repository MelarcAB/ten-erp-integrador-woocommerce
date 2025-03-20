<?php

use App\Http\Controllers\Auth\ConfirmablePasswordController;
use App\Http\Controllers\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Auth\EmailVerificationPromptController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Auth\VerifyEmailController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\Auth\AuthenticatedSessionController;
use Illuminate\Http\Request;


// Ruta de login (genera token)
Route::post('/login', [AuthenticatedSessionController::class, 'login'])->name('api.login');

// Ruta de logout (revoca token) - Protegida con Sanctum
Route::post('/logout', [AuthenticatedSessionController::class, 'logout'])->middleware('auth:sanctum')->name('api.logout');

// Ruta protegida para probar autenticación
Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return response()->json([
        'message' => 'Usuario autenticado',
        'user' => $request->user(),
    ]);
});
