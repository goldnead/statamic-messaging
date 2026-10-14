<?php

namespace Goldnead\Messaging\Http\Controllers;

use Goldnead\Messaging\Models\Conversation;
use Goldnead\Messaging\Models\Message;
use Goldnead\Messaging\Services\ConversationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class RealtimeController extends Controller
{
    /**
     * The message as the signed-in person may see it. A foreign conversation,
     * a hidden message and one that belongs elsewhere all look the same: 404.
     */
    public function message(Request $request, ConversationService $conversations, string $conversation, string $message): JsonResponse
    {
        $user = $request->user();
        $model = Conversation::query()->find((int) $conversation);

        abort_unless($model && Gate::forUser($user)->allows('view', $model), 404);

        $found = Message::query()->find((int) $message);
        $payload = $found ? $conversations->present($model, $found, $user) : null;

        abort_if($payload === null, 404);

        return response()->json($payload)->header('Cache-Control', 'private, no-store');
    }

    /** The prebuilt browser script, so an app needs no JavaScript build for it. */
    public function script(): BinaryFileResponse
    {
        $file = __DIR__.'/../../../resources/dist/realtime/messaging-realtime.js';

        abort_unless(is_file($file), 404);

        return response()->file($file, [
            'Content-Type' => 'text/javascript; charset=utf-8',
            'Cache-Control' => 'public, max-age=3600',
        ])->setAutoEtag();
    }
}
