<?php

namespace App\Services;

use App\Services\Contracts\ApiResponseServiceInterface;
use Illuminate\Http\JsonResponse;

class ApiResponseService implements ApiResponseServiceInterface
{

     /**
     * Formatea una respuesta de éxito.
     */
    public function success(array $data = [], string $message = 'Request successful', int $code = 200): JsonResponse
    {
        return response()->json([
            'status' => 'ok', 
            'message' => $message,
            'data' => empty($data) ? (object)[] : $data, 
            'meta' => [
                'timestamp' => now()->toIso8601String(),
            ],
        ], $code);
    }

    /**
     * Formatea una respuesta de error genérica.
     */
    public function error(string $message = 'Something went wrong', int $code = 500, array $data = []): JsonResponse
    {
        return response()->json([
            'status' => 'error',
            'message' => $message,
            'data' => empty($data) ? (object)[] : $data,
            'meta' => [
                'timestamp' => now()->toIso8601String(),
            ],
        ], $code);
    }

    /**
     * Formatea una respuesta de error de validación.
     */
    public function validationError(array $errors, string $message = 'Validation errors', int $code = 422): JsonResponse
    {
        return response()->json([
            'status' => 'fail',
            'message' => $message,
            'errors' => $errors, 
            'meta' => [
                'timestamp' => now()->toIso8601String(),
            ],
        ], $code);
    }

    /**
     * Respuesta cuando un recurso no es encontrado.
     */
    public function notFound(string $message = 'Resource not found', array $data = []): JsonResponse
    {
        return $this->error($message, 404, $data);
    }

}