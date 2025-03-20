<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Http\Requests\Auth\LoginRequest;
use App\Services\Contracts\ApiResponseServiceInterface;

class AuthenticatedSessionController extends Controller
{
    protected ApiResponseServiceInterface $apiResponse;

    public function __construct(ApiResponseServiceInterface $apiResponse)
    {
        $this->apiResponse = $apiResponse;
    }


    public function login(LoginRequest $request)
    {
        // Verificar credenciales
        if (!Auth::attempt($request->only('email', 'password'))) {
            return $this->apiResponse->error('Credenciales inválidas', 401);
        }

        $user = Auth::user();
        $user->tokens()->delete();

        // Generar un nuevo token para la API
        $token = $user->createToken('API Token')->plainTextToken;

        return $this->apiResponse->success([
            'user' => $user,
            'token' => $token
        ], __('auth.ok'));
    }

    /**
     * Destroy an authenticated session (Logout API).
     */
    public function logout(Request $request)
    {
        // Revocar el token actual
        $request->user()->tokens()->delete();

        return $this->apiResponse->success([], __('auth.logout'));
    }
}
