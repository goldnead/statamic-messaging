<?php

namespace Goldnead\Messaging\Broadcasting;

use Goldnead\Messaging\Support\Realtime;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A message was sent, hidden or restored.
 *
 * - `ShouldBroadcastNow`: the signal leaves in the same request instead of
 *   waiting for the next poll of a queue worker (seconds with the database
 *   driver). {@see Realtime::announce()} catches
 *   what a dead server throws, so sending never fails because of it.
 * - Private channels only, one per conversation and one per person.
 * - The payload is two ids. No text, no name, no hint of an anonymous
 *   identity: the name a viewer sees depends on the viewer, so each browser
 *   fetches its own rendering from an authorised endpoint.
 */
class MessageBroadcast implements ShouldBroadcastNow
{
    use Dispatchable;

    public const SENT = 'sent';

    public const HIDDEN = 'hidden';

    public const RESTORED = 'restored';

    /** @param  list<string>  $userKeys */
    public function __construct(
        public readonly string $type,
        public readonly int $conversationId,
        public readonly int $messageId,
        public readonly array $userKeys = [],
    ) {}

    /** @return list<Channel> */
    public function broadcastOn(): array
    {
        $channels = [new PrivateChannel('messaging.conversation.'.$this->conversationId)];

        foreach ($this->userKeys as $key) {
            $channels[] = new PrivateChannel('messaging.user.'.$key);
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'message.'.$this->type;
    }

    /** @return array{conversation: int, message: int} */
    public function broadcastWith(): array
    {
        return ['conversation' => $this->conversationId, 'message' => $this->messageId];
    }
}
