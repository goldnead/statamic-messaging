<?php

namespace Goldnead\Messaging\Scopes\Filters;

use Statamic\Query\Scopes\Filter;

/**
 * Statamic registers scopes globally and `Filter::visibleTo()` defaults to
 * `true`, so a filter that does not answer that question turns up on the
 * Entries, Assets and Users listings as well. Everything in this folder is
 * scoped to the one listing it was written for.
 */
abstract class ConversationFilter extends Filter
{
    public const LISTING_KEY = 'messaging';

    public function visibleTo($key)
    {
        return $key === self::LISTING_KEY;
    }

    /**
     * The yes/no select every flag filter shares.
     *
     * @return array<string, mixed>
     */
    protected function yesNo(string $handle, string $display): array
    {
        return [
            $handle => [
                'display' => $display,
                'type' => 'select',
                'clearable' => true,
                'placeholder' => __('messaging::cp.filter_any'),
                'options' => [
                    'yes' => __('messaging::cp.yes'),
                    'no' => __('messaging::cp.no'),
                ],
            ],
        ];
    }

    protected function yesNoBadge(string $label, mixed $value): string
    {
        return $label.': '.($value === 'yes' ? __('messaging::cp.yes') : __('messaging::cp.no'));
    }
}
