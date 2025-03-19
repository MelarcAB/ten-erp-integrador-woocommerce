<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ViewLogs
{
   /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        //si el usuario no está logeado, fuera
        if (!auth()->check()) {
            abort(403, 'Unauthorized action.');
        }
        //obtener los roles del usuario y validar que sea admin
       //usar laravel spatie
       $roles = auth()->user()->roles;
         foreach ($roles as $role) {
              if ($role->name == 'admin') {
                return $next($request);
              }
            }
        abort(403, 'Unauthorized action.');
    }
}
