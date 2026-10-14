<?php

namespace Goldnead\Messaging\Scopes\Filters;

class Blocked extends ConversationFilter
{
    protected static $handle = 'messaging_blocked';

    public static function title()
    {
        return __('messaging::cp.filter_blocked');
    }

    public function fieldItems()
    {
        return $this->yesNo('blocked', __('messaging::cp.filter_blocked'));
    }

    public function apply($query, $values)
    {
        match ($values['blocked'] ?? null) {
            'yes' => $query->whereHas('participants', fn ($q) => $q->where('blocked', true)),
            'no' => $query->whereDoesntHave('participants', fn ($q) => $q->where('blocked', true)),
            default => null,
        };
    }

    public function badge($values)
    {
        return $this->yesNoBadge(__('messaging::cp.filter_blocked'), $values['blocked'] ?? null);
    }
}
