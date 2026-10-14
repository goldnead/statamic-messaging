<?php

namespace Goldnead\Messaging\Scopes\Filters;

class HiddenMessages extends ConversationFilter
{
    protected static $handle = 'messaging_hidden';

    public static function title()
    {
        return __('messaging::cp.filter_hidden');
    }

    public function fieldItems()
    {
        return $this->yesNo('hidden', __('messaging::cp.filter_hidden'));
    }

    public function apply($query, $values)
    {
        match ($values['hidden'] ?? null) {
            'yes' => $query->whereHas('messages', fn ($q) => $q->where('hidden', true)),
            'no' => $query->whereDoesntHave('messages', fn ($q) => $q->where('hidden', true)),
            default => null,
        };
    }

    public function badge($values)
    {
        return $this->yesNoBadge(__('messaging::cp.filter_hidden'), $values['hidden'] ?? null);
    }
}
