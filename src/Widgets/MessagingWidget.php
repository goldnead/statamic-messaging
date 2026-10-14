<?php

namespace Goldnead\Messaging\Widgets;

use Goldnead\Messaging\Support\MessagingStats;
use Illuminate\Support\Facades\Gate;
use Statamic\Widgets\VueComponent;
use Statamic\Widgets\Widget;

class MessagingWidget extends Widget
{
    protected static $handle = 'messaging';

    public static function title()
    {
        return __('messaging::cp.nav');
    }

    public function component()
    {
        if (! Gate::allows('view messaging')) {
            return;
        }

        return VueComponent::render('messaging-widget', [
            'title' => $this->config('title', __('messaging::cp.nav')),
            'url' => cp_route('messaging.index'),
            'stats' => MessagingStats::summary(),
        ]);
    }
}
