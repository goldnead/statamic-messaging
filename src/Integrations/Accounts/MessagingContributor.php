<?php

namespace Goldnead\Messaging\Integrations\Accounts;

use Goldnead\Accounts\Contracts\ContributesPersonalData;
use Goldnead\Accounts\Contracts\ErasesPersonalData;
use Goldnead\Accounts\PersonalData\ErasureResult;
use Goldnead\Messaging\Models\Conversation;
use Goldnead\Messaging\Models\ConversationParticipant;
use Goldnead\Messaging\Models\Message;
use Illuminate\Support\Facades\DB;
use Statamic\Auth\User;

/**
 * Only tagged by the service provider when goldnead/statamic-accounts is there.
 *
 * Erasure: a conversation the account started goes entirely (it was theirs).
 * In a conversation they only received, their messages stay for the other
 * side but lose the sender and the text.
 */
class MessagingContributor implements ContributesPersonalData, ErasesPersonalData
{
    public function key(): string
    {
        return 'messaging';
    }

    public function label(): string
    {
        return 'Messaging';
    }

    public function available(): bool
    {
        return true;
    }

    public function collect(User $user): array
    {
        $id = (string) $user->getAuthIdentifier();
        $conversations = ConversationParticipant::where('user_id', $id)->pluck('conversation_id');

        return [
            'conversations' => $conversations->all(),
            'messages' => Message::where('sender_user_id', $id)->get(['conversation_id', 'body', 'created_at'])->toArray(),
        ];
    }

    public function blockers(User $user, string $audience = 'customer'): array
    {
        return [];
    }

    public function erase(User $user): ErasureResult
    {
        $id = (string) $user->getAuthIdentifier();

        return DB::transaction(function () use ($id) {
            // A conversation addressed to this person stays for the other side,
            // without the key that would still point at the erased account.
            Conversation::where('recipient_user_id', $id)->update(['recipient_user_id' => null]);

            $own = ConversationParticipant::where('user_id', $id)->where('role', ConversationParticipant::ROLE_INITIATOR)->pluck('conversation_id');
            $deleted = Conversation::whereIn('id', $own)->delete();

            // Through the model, not a bulk update: only the model's cast writes the body encrypted.
            $anonymized = 0;
            Message::where('sender_user_id', $id)->eachById(function (Message $message) use (&$anonymized) {
                $message->update(['sender_user_id' => null, 'body' => '']);
                $anonymized++;
            });
            $participations = ConversationParticipant::where('user_id', $id)->delete();

            return new ErasureResult($this->key(), deleted: array_filter(['conversations' => $deleted, 'participations' => $participations]), anonymized: array_filter(['messages' => $anonymized]));
        });
    }
}
