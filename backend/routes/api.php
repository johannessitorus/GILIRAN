<?php

use App\Http\Controllers\FoundationController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->controller(FoundationController::class)->group(function () {
    Route::post('/auth/login', 'login')->middleware('throttle:10,1');
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/auth/me', 'me');
        Route::post('/auth/logout', 'logout');
        foreach (['departments', 'users', 'assignments'] as $resource) {
            Route::get("/{$resource}", 'index')->defaults('resource', $resource);
            Route::get("/{$resource}/{id}", 'show')->whereNumber('id')->defaults('resource', $resource);
            Route::post("/{$resource}", 'store')->defaults('resource', $resource);
            Route::patch("/{$resource}/{id}", 'update')->whereNumber('id')->defaults('resource', $resource);
        }
        Route::post('/assignments/{id}/cancel', 'cancel')->whereNumber('id');
    });
});
