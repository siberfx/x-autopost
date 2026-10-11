<?php

declare(strict_types=1);

use Illuminate\Routing\Middleware\ValidateSignature;
use Illuminate\Support\Facades\Route;
use Siberfx\XAutopost\Http\Controllers\ConnectionController;
use Siberfx\XAutopost\Http\Controllers\OAuthController;
use Siberfx\XAutopost\Http\Controllers\ShareController;

Route::group([
    'prefix' => (string) config('x-autopost.routes.prefix', 'x'),
    'as' => (string) config('x-autopost.routes.name', 'x-autopost.'),
    'middleware' => (array) config('x-autopost.routes.middleware', ['web', 'auth', 'can:manage-x-autopost']),
], function (): void {
    Route::get('redirect', [OAuthController::class, 'redirect'])->name('redirect');
    Route::get('callback', [OAuthController::class, 'callback'])->name('callback');
    Route::get('connection', [ConnectionController::class, 'show'])->name('connection.show');
    Route::delete('connection', [ConnectionController::class, 'destroy'])->name('connection.destroy');
    Route::post('share', [ShareController::class, 'signed'])
        ->middleware(ValidateSignature::class)
        ->name('share.signed');
    Route::post('share/{type}/{id}', ShareController::class)->name('share');
});
