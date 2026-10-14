<?php

namespace Goldnead\Messaging\Listeners;

use Goldnead\Messaging\Broadcasting\MessageBroadcast;
use Goldnead\Messaging\Events\MessageSent;
use Goldnead\Messaging\Support\Realtime;

/** Runs after the transaction commits ({@see MessageSent}), so the browser never fetches a message that is not there yet. */
class BroadcastMessage
{
    public function handle(MessageSent $event): void
    {
        $message = $event->message;

        // A hidden message is never delivered, not even as a signal.
        if ($message->hidden) {
            return;
        }

        Realtime::announce(MessageBroadcast::SENT, $message);
    }
}
