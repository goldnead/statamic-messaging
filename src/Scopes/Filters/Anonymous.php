<?php

namespace Goldnead\Messaging\Scopes\Filters;

class Anonymous extends ConversationFilter
{
    protected static $handle = 'messaging_anonymous';

    public static function title()
    {
        return __('messaging::cp.filter_anonymous');
    }

    public function fieldItems()
    {
        return $this->yesNo('anonymous', __('messaging::cp.filter_anonymous'));
    }

    public function apply($query, $values)
    {
        match ($values['anonymous'] ?? null) {
            'yes' => $query->where('anonymous', true),
            'no' => $query->where('anonymous', false),
            default => null,
        };
    }

    public function badge($values)
    {
        return $this->yesNoBadge(__('messaging::cp.filter_anonymous'), $values['anonymous'] ?? null);
    }
}
