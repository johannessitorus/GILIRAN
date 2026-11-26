<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\FoundationController;
use App\Http\Controllers\HandoverController;
use App\Http\Controllers\SwapController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/auth')->controller(AccountController::class)->group(function () {
    Route::post('/register', 'register')->middleware('throttle:10,1,registration');
    Route::post('/forgot-password', 'forgot')->middleware('throttle:10,1,password-reset');
    Route::post('/invitations', 'invite')->middleware(['auth:sanctum', 'throttle:10,1,invitations']);
    Route::post('/links/{purpose}', 'complete')
        ->whereIn('purpose', ['verify-owner', 'accept-invite', 'reset-password'])
        ->middleware('throttle:10,1,account-links');
});

Route::prefix('v1')->controller(FoundationController::class)->group(function () {
    Route::post('/auth/login', 'login')->middleware('throttle:10,1');
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/auth/me', 'me');
        Route::post('/auth/logout', 'logout');
        Route::get('/shift-types', 'shiftTypes');
        foreach (['departments', 'users', 'assignments'] as $resource) {
            Route::get("/{$resource}", 'index')->defaults('resource', $resource);
            Route::get("/{$resource}/{id}", 'show')->whereNumber('id')->defaults('resource', $resource);
            Route::post("/{$resource}", 'store')->defaults('resource', $resource);
            Route::patch("/{$resource}/{id}", 'update')->whereNumber('id')->defaults('resource', $resource);
        }
        Route::post('/assignments/{id}/cancel', 'cancel')->whereNumber('id');
    });
});

Route::prefix('v1')->middleware('auth:sanctum')->controller(HandoverController::class)->group(function () {
    Route::get('/audit-logs', 'auditIndex');
    Route::get('/handovers', 'index');
    Route::post('/handovers', 'store');
    Route::get('/handovers/{id}', 'show')->whereNumber('id');
    Route::patch('/handovers/{id}', 'change')->whereNumber('id');
    Route::post('/handovers/{id}/{action}', 'change')->whereNumber('id')
        ->whereIn('action', ['adopt', 'submit', 'acknowledge']);
    Route::post('/handovers/{id}/tasks', 'task')->whereNumber('id');
    Route::match(['PATCH', 'DELETE'], '/handovers/{id}/tasks/{taskId}', 'task')->whereNumber(['id', 'taskId']);
});

Route::prefix('v1/swaps')->middleware('auth:sanctum')->controller(SwapController::class)->group(function () {
    Route::get('/', 'index');
    Route::post('/', 'store');
    Route::get('/{id}', 'show')->whereNumber('id');
    Route::post('/{id}/{action}', 'decide')->whereNumber('id')
        ->whereIn('action', ['accept', 'peer-reject', 'approve', 'reject', 'cancel']);
});
