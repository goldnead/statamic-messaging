<?php

namespace Goldnead\Messaging\Tests\Feature;

use Goldnead\Messaging\Broadcasting\MessageBroadcast;
use Goldnead\Messaging\Services\ConversationService;
use Goldnead\Messaging\Tests\TestCase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;

/** The default setup of every app that has not installed a websocket server: nothing happens, nothing fails. */
class RealtimeOffTest extends TestCase
{
    public function test_without_a_driver_nothing_is_broadcast_and_nothing_fails(): void
    {
        foreach (['null', 'log'] as $driver) {
            config(['broadcasting.default' => $driver]);
            Event::fake([MessageBroadcast::class]);

            $singer = $this->makeUser("s-$driver@example.test");
            $admin = $this->makeUser("a-$driver@example.test");
            $conversation = app(ConversationService::class)->start($singer, $this->makeTeam($admin), 'Hallo');
            app(ConversationService::class)->reply($conversation, $admin, 'Antwort');

            Event::assertNotDispatched(MessageBroadcast::class);
        }
    }

    public function test_no_routes_are_registered_when_off(): void
    {
        $this->assertFalse(Route::has('messaging.realtime.message'));
        $this->assertFalse(Route::has('messaging.realtime.script'));
    }
}
