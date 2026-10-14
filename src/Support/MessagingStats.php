<?php

namespace Goldnead\Messaging\Support;

use Goldnead\Messaging\Models\Conversation;
use Goldnead\Messaging\Models\Message;

/**
 * Counts only. Nothing here reads a body, a name or a subject.
 */
class MessagingStats
{
    /** @var list<int> */
    public const WINDOWS = [7, 30];

    /**
     * @return array{windows: list<array{days: int, conversations: int, messages: int}>, flagged: int}
     */
    public static function summary(): array
    {
        $windows = [];

        foreach (self::WINDOWS as $days) {
            $since = now()->subDays($days);

            $windows[] = [
                'days' => $days,
                'conversations' => Conversation::query()->where('created_at', '>=', $since)->count(),
                'messages' => Message::query()->where('created_at', '>=', $since)->count(),
            ];
        }

        return [
            'windows' => $windows,
            'flagged' => Conversation::query()->whereHas('participants', fn ($q) => $q->where('flagged', true))->count(),
        ];
    }
}
