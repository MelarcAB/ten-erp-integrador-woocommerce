<?php

use App\Http\Controllers\CategoriaController;
use App\Http\Controllers\ProductoController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use App\Http\Controllers\HomeController;

Route::get('/', function () {
    if (auth()->check()) {
        return redirect()->route('dashboard');
    }

    //melarc.ab
    //Cambiar el redirect si no se quiere que redireccione a login
    return redirect()->route('login');
})->name('home');

Route::get('home', function () {
    //asignar el rol admin al usuario
    auth()->user()->assignRole('admin');
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');


Route::get('categorias', [CategoriaController::class, 'index'])->name('categorias')->middleware(['auth', 'verified']);
Route::get('productos', [ProductoController::class, 'index'])->name('productos')->middleware(['auth', 'verified']);

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
