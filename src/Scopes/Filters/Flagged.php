<?php

namespace Goldnead\Messaging\Scopes\Filters;

class Flagged extends ConversationFilter
{
    protected static $handle = 'messaging_flagged';

    public static function title()
    {
        return __('messaging::cp.filter_flagged');
    }

    public function fieldItems()
    {
        return $this->yesNo('flagged', __('messaging::cp.filter_flagged'));
    }

    public function apply($query, $values)
    {
        match ($values['flagged'] ?? null) {
            'yes' => $query->whereHas('participants', fn ($q) => $q->where('flagged', true)),
            'no' => $query->whereDoesntHave('participants', fn ($q) => $q->where('flagged', true)),
            default => null,
        };
    }

    public function badge($values)
    {
        return $this->yesNoBadge(__('messaging::cp.filter_flagged'), $values['flagged'] ?? null);
    }
}
