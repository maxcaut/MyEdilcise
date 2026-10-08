<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\FileController;
use App\Http\Controllers\TelegramController;
use App\Http\Middleware\SupabaseSession;
use Illuminate\Support\Facades\Route;

Route::view('/', 'login')->name('login');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1')->name('login.store');
Route::post('/telegram/webhook', [TelegramController::class, 'webhook'])->name('telegram.webhook');
Route::middleware(SupabaseSession::class)->group(function (): void {
    Route::get('/dashboard', [FileController::class, 'dashboard'])->name('dashboard');
    Route::get('/documenti/{id}', [FileController::class, 'show'])->whereNumber('id')->name('documents.show');
    Route::get('/documenti/{id}/file', [FileController::class, 'download'])->whereNumber('id')->name('documents.download');
    Route::post('/documenti', [FileController::class, 'store'])->name('documents.store');
    Route::delete('/documenti/{id}', [FileController::class, 'destroy'])->whereNumber('id')->name('documents.destroy');
    Route::get('/profilo', [TelegramController::class, 'profile'])->name('profile');
    Route::post('/profilo/telegram', [TelegramController::class, 'store'])->middleware('throttle:5,1')->name('profile.telegram.store');
    Route::delete('/profilo/telegram', [TelegramController::class, 'destroy'])->name('profile.telegram.destroy');
    Route::delete('/profilo/telegram/{id}', [TelegramController::class, 'revoke'])->whereNumber('id')->name('profile.telegram.revoke');
    Route::post('/profilo', [AuthController::class, 'updateProfile'])->name('profile.update');
    Route::post('/profilo/password', [AuthController::class, 'updatePassword'])->middleware('throttle:5,1')->name('profile.password');
    Route::post('/utenti', [AuthController::class, 'createUser'])->middleware('throttle:10,1')->name('users.store');
    Route::patch('/utenti/{id}/ruolo', [AuthController::class, 'updateUserRole'])->whereUuid('id')->name('users.role.update');
    Route::delete('/utenti/{id}', [AuthController::class, 'deleteUser'])->whereUuid('id')->name('users.destroy');
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
});
