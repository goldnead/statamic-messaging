<?php

use Goldnead\Messaging\Http\Controllers\RealtimeController;
use Illuminate\Support\Facades\Route;

/*
| Only loaded while realtime is on (see ServiceProvider::bootRealtime()). Not
| named web.php on purpose: Statamic would load a web.php unconditionally.
*/
Route::middleware('web')->prefix('messaging/realtime')->group(function () {
    Route::get('/{conversation}/messages/{message}', [RealtimeController::class, 'message'])
        ->middleware('auth')
        ->whereNumber(['conversation', 'message'])
        ->name('messaging.realtime.message');
});

Route::middleware('web')->get('messaging/realtime.js', [RealtimeController::class, 'script'])->name('messaging.realtime.script');
