<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response;
use App\Services\Contracts\ApiResponseServiceInterface;


class ApiAuthenticationMiddleware
{
    /**
     * The authentication factory implementation.
     *
     * @var \Illuminate\Contracts\Auth\Factory
     */
    protected $auth;

    /**
     * The API response service.
     *
     * @var \App\Services\Contracts\ApiResponseServiceInterface
     */
    protected ApiResponseServiceInterface $apiResponse;

    /**
     * Create a new middleware instance.
     *
     * @param  \Illuminate\Contracts\Auth\Factory  $auth
     * @param  \App\Services\Contracts\ApiResponseServiceInterface  $apiResponse
     * @return void
     */
    public function __construct(AuthFactory $auth, ApiResponseServiceInterface $apiResponse)
    {
        $this->auth = $auth;
        $this->apiResponse = $apiResponse;
    }

    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Si la petición no tiene sesión o no hay usuario, continua normalmente
        if (! $request->hasSession() || ! $request->user()) {
            return $next($request);
        }

        $guards = Collection::make(Arr::wrap(config('sanctum.guard')))
            ->mapWithKeys(fn($guard) => [$guard => $this->auth->guard($guard)])
            ->filter(fn($guard) => $guard instanceof SessionGuard);

        $shouldLogout = $guards->filter(
            fn($guard, $driver) => $request->session()->has('password_hash_' . $driver)
        )->filter(
            fn($guard, $driver) => $request->session()->get('password_hash_' . $driver) !==
                $request->user()->getAuthPassword()
        );

        if ($shouldLogout->isNotEmpty()) {
            $shouldLogout->each->logoutCurrentDevice();
            $request->session()->flush();

            // En lugar de lanzar excepción, devolvemos una respuesta estandarizada en JSON.
            return $this->apiResponse->error('Unauthenticated. Please login again.', 401);
        }

        return tap($next($request), function () use ($request, $guards) {
            if (! is_null($guard = $this->getFirstGuardWithUser($guards->keys()))) {
                $this->storePasswordHashInSession($request, $guard);
            }
        });
    }

    /**
     * Get the first authentication guard that has a user.
     *
     * @param  \Illuminate\Support\Collection  $guards
     * @return string|null
     */
    protected function getFirstGuardWithUser(Collection $guards)
    {
        return $guards->first(function ($guard) {
            $guardInstance = $this->auth->guard($guard);
            return method_exists($guardInstance, 'hasUser') &&
                $guardInstance->hasUser();
        });
    }

    /**
     * Store the user's current password hash in the session.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  string  $guard
     * @return void
     */
    protected function storePasswordHashInSession(Request $request, string $guard): void
    {
        $request->session()->put([
            "password_hash_{$guard}" => $this->auth->guard($guard)->user()->getAuthPassword(),
        ]);
    }
}
