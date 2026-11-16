<?php

use App\Http\Controllers\Api\ChatController;
use App\Http\Middleware\AuthenticateApiKey;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::middleware([AuthenticateApiKey::class, 'throttle:chat-api'])->group(function () {
        Route::post('chat', [ChatController::class, 'store']);
        Route::get('conversations/{uuid}', [ChatController::class, 'show']);
    });

    Route::post('widget/chat', [ChatController::class, 'widget'])->middleware('throttle:chat-widget');
});
