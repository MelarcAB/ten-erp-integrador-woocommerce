<?php

namespace App\Services\Contracts;
use Illuminate\Http\JsonResponse;

interface ApiResponseServiceInterface
{
    public function success(array $data = [], string $message = '', int $code = 200): JsonResponse;
    public function error(string $message = '', int $code = 500, array $data = []): JsonResponse;
    public function validationError(array $errors, string $message = '', int $code = 422): JsonResponse;
    public function notFound(string $message = '', array $data = []): JsonResponse;
}