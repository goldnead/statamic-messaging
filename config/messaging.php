<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Realtime
    |--------------------------------------------------------------------------
    |
    | `null` follows the broadcast connection: realtime is on as soon as
    | `broadcasting.default` is a `reverb` or `pusher` connection and off
    | otherwise (`null`, `log`), without an error. `true` / `false` force it.
    |
    */
    'realtime' => env('MESSAGING_REALTIME'),

    /*
    |--------------------------------------------------------------------------
    | Browser connection
    |--------------------------------------------------------------------------
    |
    | Where the browser finds the websocket server. Empty means "the host,
    | port and scheme of the page", which is right whenever the proxy routes
    | `/app` to the server on the same domain. The server side
    | (`broadcasting.connections.*.options`) may point somewhere internal.
    |
    */
    'client' => [
        'host' => env('MESSAGING_CLIENT_HOST'),
        'port' => env('MESSAGING_CLIENT_PORT'),
        'scheme' => env('MESSAGING_CLIENT_SCHEME'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Time of a message delivered live
    |--------------------------------------------------------------------------
    |
    | `timezone` null uses `app.timezone`.
    |
    */
    'time_format' => 'd.m.Y H:i',
    'timezone' => null,

];
