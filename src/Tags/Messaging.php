<?php

namespace Goldnead\Messaging\Tags;

use Goldnead\Messaging\Models\Conversation;
use Goldnead\Messaging\Support\Realtime;
use Goldnead\Teams\Support\Users;
use Illuminate\Support\Facades\Gate;
use Statamic\Facades\User;
use Statamic\Tags\Tags;

/**
 * ```antlers
 * {{ messaging:realtime conversation="{ cid }" }}   {{# in a conversation #}}
 * {{ messaging:realtime }}                          {{# in the inbox #}}
 * ```
 *
 * Writes one `<script>` that loads the bundled client and hands it its
 * channels. Empty for a guest, and while realtime is off: the tag can stay in
 * the template of an app that has no websocket server.
 */
class Messaging extends Tags
{
    protected static $handle = 'messaging';

    public function realtime(): string
    {
        $user = User::current();
        $key = Users::key($user);

        if ($key === null || ! Realtime::enabled()) {
            return '';
        }

        $channels = ['user' => 'messaging.user.'.$key, 'conversation' => null];
        $conversation = null;
        $conversationId = $this->params->get('conversation');

        if ($conversationId !== null && ctype_digit((string) $conversationId)) {
            $conversation = Conversation::query()->find((int) $conversationId);

            if ($conversation && Gate::forUser($user)->allows('view', $conversation) && ! $conversation->participantOf($user)?->blocked) {
                $channels['conversation'] = 'messaging.conversation.'.$conversation->id;
            }
        }

        $default = (string) config('broadcasting.default');
        $connection = (array) config("broadcasting.connections.$default", []);
        $client = (array) config('messaging.client', []);
        $driver = Realtime::driver() ?? 'reverb';

        $config = array_filter([
            'driver' => $driver,
            'key' => $connection['key'] ?? null,
            'cluster' => $driver === 'pusher' ? ($connection['options']['cluster'] ?? null) : null,
            'host' => $driver === 'reverb' ? ($client['host'] ?? null) : null,
            'port' => $driver === 'reverb' ? ($client['port'] ?? null) : null,
            'scheme' => $driver === 'reverb' ? ($client['scheme'] ?? null) : null,
            'auth' => '/broadcasting/auth',
            'csrf' => csrf_token(),
            'userChannel' => $channels['user'],
            'conversation' => $channels['conversation'] ? $conversation->id : null,
            'conversationChannel' => $channels['conversation'],
            'messageUrl' => str_replace(['__c__', '__m__'], ['{conversation}', '{message}'], route('messaging.realtime.message', ['conversation' => '__c__', 'message' => '__m__'], false)),
        ], fn ($value) => $value !== null && $value !== '');

        $script = route('messaging.realtime.script', [], false);
        $file = __DIR__.'/../../resources/dist/realtime/messaging-realtime.js';
        $version = is_file($file) ? substr((string) md5_file($file), 0, 8) : '0';

        return sprintf(
            '<script src="%s?v=%s" defer data-messaging-realtime="%s"></script>',
            e($script),
            $version,
            e((string) json_encode($config, JSON_UNESCAPED_SLASHES)),
        );
    }
}
