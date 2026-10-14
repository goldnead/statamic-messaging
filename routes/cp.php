<?php

use Goldnead\Messaging\Http\Controllers\Cp\ConversationController;
use Goldnead\Messaging\Http\Controllers\Cp\ModerationController;
use Illuminate\Support\Facades\Route;

Route::prefix('messaging')->name('messaging.')->group(function () {
    Route::get('/', [ConversationController::class, 'index'])->name('index');
    Route::get('/{conversation}', [ConversationController::class, 'show'])->name('show')->whereNumber('conversation');

    Route::post('/messages/{message}/hide', [ModerationController::class, 'hide'])->name('messages.hide')->whereNumber('message');
    Route::post('/messages/{message}/unhide', [ModerationController::class, 'unhide'])->name('messages.unhide')->whereNumber('message');
    Route::post('/participants/{participant}/unblock', [ModerationController::class, 'unblock'])->name('participants.unblock')->whereNumber('participant');
});
