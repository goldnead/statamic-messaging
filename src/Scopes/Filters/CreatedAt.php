<?php

namespace Goldnead\Messaging\Scopes\Filters;

use Illuminate\Support\Carbon;

class CreatedAt extends ConversationFilter
{
    protected static $handle = 'messaging_created_at';

    protected $pinned = true;

    public static function title()
    {
        return __('messaging::cp.filter_created');
    }

    public function fieldItems()
    {
        return [
            'range' => [
                'display' => __('messaging::cp.filter_created'),
                'type' => 'date',
                'mode' => 'range',
                'full_width' => true,
                'clearable' => false,
            ],
        ];
    }

    public function apply($query, $values)
    {
        $start = $this->parse($values['range']['start'] ?? null);
        $end = $this->parse($values['range']['end'] ?? null);

        if ($start) {
            $query->where('created_at', '>=', $start->startOfDay());
        }

        if ($end) {
            $query->where('created_at', '<=', $end->endOfDay());
        }
    }

    public function badge($values)
    {
        $start = $this->parse($values['range']['start'] ?? null)?->toDateString();
        $end = $this->parse($values['range']['end'] ?? null)?->toDateString();

        return __('messaging::cp.filter_created').': '.$start.' – '.$end;
    }

    /** An unparseable date is dropped rather than turned into "now". */
    protected function parse(mixed $value): ?Carbon
    {
        if (blank($value) || ! is_string($value)) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
