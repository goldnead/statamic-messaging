<?php

namespace Goldnead\Messaging\Support;

use Goldnead\Messaging\Broadcasting\MessageBroadcast;
use Goldnead\Messaging\Models\Conversation;
use Goldnead\Messaging\Models\Message;
use Throwable;

/**
 * The switch and the one way out to the websocket server.
 *
 * Realtime only ever says "message N of conversation C changed". It carries no
 * text and no name: the browser fetches what it may see from an authorised
 * endpoint, because the sender's name depends on who is looking.
 */
class Realtime
{
    /** Broadcast drivers that speak the Pusher protocol the bundled script uses. */
    public const DRIVERS = ['reverb', 'pusher'];

    public static function enabled(): bool
    {
        $forced = config('messaging.realtime');

        if ($forced !== null) {
            return (bool) $forced;
        }

        return static::driver() !== null;
    }

    /** `reverb` or `pusher`, or `null` when the default connection is something else. */
    public static function driver(): ?string
    {
        $default = config('broadcasting.default');

        if (! is_string($default)) {
            return null;
        }

        $driver = config("broadcasting.connections.$default.driver");

        return in_array($driver, static::DRIVERS, true) ? $driver : null;
    }

    /**
     * Tell everybody concerned that `$message` was sent, hidden or restored.
     * Never throws: a websocket server that is down must not stop anybody from
     * sending a message, so the failure is reported and the message stays stored.
     */
    public static function announce(string $type, Message $message): void
    {
        if (! static::enabled()) {
            return;
        }

        try {
            broadcast(new MessageBroadcast(
                $type,
                (int) $message->conversation_id,
                (int) $message->id,
                static::recipients($message->conversation),
            ));
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Keys of the people who get a signal on their own channel. A participant
     * who blocked the conversation is left out, as they are on its channel.
     *
     * @return list<string>
     */
    public static function recipients(Conversation $conversation): array
    {
        return $conversation->participants()->where('blocked', false)->pluck('user_id')
            ->map(fn ($id) => (string) $id)->unique()->values()->all();
    }
}
