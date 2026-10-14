<?php

namespace Goldnead\Messaging\Http\Controllers\Cp;

use Goldnead\Messaging\Exceptions\MessagingException;
use Goldnead\Messaging\Models\ConversationParticipant;
use Goldnead\Messaging\Models\Message;
use Goldnead\Messaging\Services\ConversationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Statamic\Facades\CP\Toast;

/** Every action needs `manage messaging`; looking is `view messaging`. */
class ModerationController extends Controller
{
    public function __construct(private readonly ConversationService $conversations) {}

    public function hide(Request $request, Message $message): RedirectResponse
    {
        Gate::authorize('manage messaging');

        $data = $request->validate(['reason' => ['required', 'string', 'max:'.ConversationService::MAX_REASON_LENGTH]]);

        try {
            $this->conversations->hideMessage($message, $data['reason']);
        } catch (MessagingException $e) {
            Toast::error($e->getMessage());

            return $this->back($message->conversation_id);
        }

        Toast::success(__('messaging::cp.message_hidden'));

        return $this->back($message->conversation_id);
    }

    public function unhide(Message $message): RedirectResponse
    {
        Gate::authorize('manage messaging');

        $this->conversations->unhideMessage($message);
        Toast::success(__('messaging::cp.message_unhidden'));

        return $this->back($message->conversation_id);
    }

    public function unblock(ConversationParticipant $participant): RedirectResponse
    {
        Gate::authorize('manage messaging');

        $this->conversations->unblock($participant);
        Toast::success(__('messaging::cp.participant_unblocked'));

        return $this->back($participant->conversation_id);
    }

    private function back(int $conversationId): RedirectResponse
    {
        return redirect(cp_route('messaging.show', ['conversation' => $conversationId]));
    }
}
