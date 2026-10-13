<?php

namespace Goldnead\Messaging\Events;

use Goldnead\Messaging\Models\Message;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired once per stored message, after the transaction commits. Listeners
 * (a mail, a notification) must not reveal the sender of an anonymous
 * conversation: ask `$message->conversation->displayName()`.
 */
class MessageSent implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly Message $message) {}
}
