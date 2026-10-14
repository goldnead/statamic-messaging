<?php

namespace Goldnead\Messaging\Tests;

use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Broadcasting\Broadcasters\UsePusherChannelConventions;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * A broadcaster that checks channel authorisation like the Pusher one does
 * (the `log` driver skips it) and sends nothing anywhere.
 */
class ProbeBroadcaster extends Broadcaster
{
    use UsePusherChannelConventions;

    public function auth($request)
    {
        $channel = $this->normalizeChannelName((string) $request->channel_name);

        if ($channel === '' || ! $this->retrieveUser($request, $channel)) {
            throw new AccessDeniedHttpException;
        }

        return parent::verifyUserCanAccessChannel($request, $channel);
    }

    public function validAuthenticationResponse($request, $result)
    {
        return ['auth' => 'probe'];
    }

    public function broadcast(array $channels, $event, array $payload = []) {}
}
