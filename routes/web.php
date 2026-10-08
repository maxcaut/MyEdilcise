<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\FileController;
use App\Http\Middleware\SupabaseSession;
use Illuminate\Support\Facades\Route;

Route::view('/', 'login')->name('login');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1')->name('login.store');
Route::middleware(SupabaseSession::class)->group(function (): void {
    Route::get('/dashboard', [FileController::class, 'dashboard'])->name('dashboard');
    Route::get('/documenti/{id}', [FileController::class, 'show'])->whereNumber('id')->name('documents.show');
    Route::get('/documenti/{id}/file', [FileController::class, 'download'])->whereNumber('id')->name('documents.download');
    Route::post('/documenti', [FileController::class, 'store'])->name('documents.store');
    Route::delete('/documenti/{id}', [FileController::class, 'destroy'])->whereNumber('id')->name('documents.destroy');
    Route::view('/profilo', 'profile')->name('profile');
    Route::post('/profilo', [AuthController::class, 'updateProfile'])->name('profile.update');
    Route::post('/profilo/password', [AuthController::class, 'updatePassword'])->middleware('throttle:5,1')->name('profile.password');
    Route::post('/utenti', [AuthController::class, 'createUser'])->middleware('throttle:10,1')->name('users.store');
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
});
