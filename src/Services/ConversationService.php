<?php

namespace Goldnead\Messaging\Services;

use Goldnead\Messaging\Broadcasting\MessageBroadcast;
use Goldnead\Messaging\Events\MessageSent;
use Goldnead\Messaging\Exceptions\MessagingException;
use Goldnead\Messaging\Models\Conversation;
use Goldnead\Messaging\Models\ConversationParticipant;
use Goldnead\Messaging\Models\Message;
use Goldnead\Messaging\Policies\ConversationPolicy;
use Goldnead\Messaging\Support\Realtime;
use Goldnead\Teams\Models\Team;
use Goldnead\Teams\Support\Users;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Every write goes through here, and every write is authorised by
 * {@see ConversationPolicy}.
 */
class ConversationService
{
    /** Characters per message, counted after tags are stripped. */
    public const MAX_BODY_LENGTH = 10000;

    /** Characters of a moderation reason. */
    public const MAX_REASON_LENGTH = 255;

    /**
     * Open a conversation with a team. The initiator becomes `initiator`,
     * every other team member a `member`. A team with nobody else in it
     * cannot receive: fewer than two parties is no conversation.
     *
     * Alternatively pass `$recipient = null` and a person as `$recipientUser`:
     * exactly one of the two must be set. The person becomes the only `member`.
     *
     * @param  array{0: string, 1: string|int}|null  $subject  Type and id of what the request is about.
     */
    public function start(mixed $initiator, ?Team $recipient, string $body, bool $anonymous = false, ?array $subject = null, mixed $recipientUser = null): Conversation
    {
        if (($recipient === null) === ($recipientUser === null)) {
            throw MessagingException::ambiguousRecipient();
        }

        $initiatorKey = Users::key($initiator) ?? throw MessagingException::noRecipient();
        $body = $this->clean($body);

        $userKey = $recipientUser === null ? null : (Users::key($recipientUser) ?? throw MessagingException::noRecipient());
        $others = $recipient
            ? $recipient->members()->pluck('user_id')->map(fn ($id) => (string) $id)->reject(fn ($id) => $id === $initiatorKey)->unique()->values()
            : collect([$userKey])->reject(fn ($id) => $id === $initiatorKey)->values();

        if ($others->isEmpty()) {
            throw MessagingException::noRecipient();
        }

        $message = DB::transaction(function () use ($initiatorKey, $recipient, $userKey, $body, $anonymous, $subject, $others) {
            $conversation = Conversation::create([
                'subject_type' => $subject[0] ?? null,
                'subject_id' => isset($subject[1]) ? (string) $subject[1] : null,
                'recipient_team_id' => $recipient?->id,
                'recipient_user_id' => $userKey,
                'anonymous' => $anonymous,
            ]);

            $conversation->participants()->create(['user_id' => $initiatorKey, 'role' => ConversationParticipant::ROLE_INITIATOR]);
            foreach ($others as $id) {
                $conversation->participants()->create(['user_id' => $id, 'role' => ConversationParticipant::ROLE_MEMBER]);
            }

            return $this->store($conversation, $initiatorKey, $body);
        });

        return $message->conversation;
    }

    public function reply(Conversation $conversation, mixed $user, string $body): Message
    {
        Gate::forUser($user)->authorize('reply', $conversation);
        $body = $this->clean($body);

        return DB::transaction(function () use ($conversation, $user, $body) {
            $key = $this->participant($conversation, $user)->user_id;

            return $this->store($conversation, $key, $body);
        });
    }

    /** The initiator reveals themselves: the conversation stops being anonymous. */
    public function shareProfile(Conversation $conversation, mixed $user): Conversation
    {
        Gate::forUser($user)->authorize('shareProfile', $conversation);

        $conversation->update(['profile_shared' => true, 'anonymous' => false]);

        return $conversation;
    }

    /** Blocks only for the blocking person: they can no longer send. */
    public function block(Conversation $conversation, mixed $user): ConversationParticipant
    {
        Gate::forUser($user)->authorize('block', $conversation);

        return tap($this->participant($conversation, $user))->update(['blocked' => true]);
    }

    public function archive(Conversation $conversation, mixed $user, bool $archived = true): ConversationParticipant
    {
        Gate::forUser($user)->authorize('archive', $conversation);

        return tap($this->participant($conversation, $user))->update(['archived' => $archived]);
    }

    /**
     * The thread as `$viewer` may see it: hidden messages dropped, the sender
     * named through {@see Conversation::displayName()}. This is the shape a
     * page or a mail should render, never the raw rows.
     *
     * @return Collection<int, array{id: int, sender: string, mine: bool, body: string, read: bool, sent_at: Carbon|null}>
     */
    public function thread(Conversation $conversation, mixed $viewer): Collection
    {
        Gate::forUser($viewer)->authorize('view', $conversation);

        $me = Users::key($viewer);
        $messages = $conversation->messages()->where('hidden', false)->orderBy('id')->get();

        // One name per sender, one initiator lookup per thread, however long it is.
        $initiator = $conversation->isMasked() ? $conversation->initiatorKey() : null;
        $names = [];
        $nameOf = function (string $key) use (&$names, $conversation, $viewer, $initiator): string {
            return $names[$key] ??= $conversation->displayName($key, $viewer, $initiator);
        };

        return $messages->toBase()->map(fn (Message $m) => [
            'id' => $m->id,
            'sender' => $m->sender_user_id === null ? Conversation::DELETED_LABEL : $nameOf($m->sender_user_id),
            'mine' => $m->sender_user_id === $me,
            'body' => $m->body,
            'read' => $m->read_at !== null,
            'sent_at' => $m->created_at,
        ]);
    }

    /**
     * One message as `$viewer` may see it, in the shape of {@see thread()} plus
     * a formatted `time`. `null` for a hidden message or one of another
     * conversation: the live endpoint answers 404 for both.
     *
     * @return array{id: int, sender: string, mine: bool, body: string, read: bool, sent_at: string|null, time: string}|null
     */
    public function present(Conversation $conversation, Message $message, mixed $viewer): ?array
    {
        Gate::forUser($viewer)->authorize('view', $conversation);

        if ($message->conversation_id !== $conversation->id || $message->hidden) {
            return null;
        }

        $sentAt = $message->created_at?->copy()->timezone(config('messaging.timezone') ?: config('app.timezone'));

        return [
            'id' => $message->id,
            'sender' => $message->sender_user_id === null ? Conversation::DELETED_LABEL : $conversation->displayName($message->sender_user_id, $viewer),
            'mine' => $message->sender_user_id === Users::key($viewer),
            'body' => $message->body,
            'read' => $message->read_at !== null,
            'sent_at' => $sentAt?->toIso8601String(),
            'time' => $sentAt?->format(config('messaging.time_format', 'd.m.Y H:i')) ?? '',
        ];
    }

    /** Marks what the others wrote as read. */
    public function markRead(Conversation $conversation, mixed $user): void
    {
        Gate::forUser($user)->authorize('view', $conversation);

        $conversation->messages()->whereNull('read_at')
            ->where(fn ($q) => $q->where('sender_user_id', '!=', Users::key($user))->orWhereNull('sender_user_id'))
            ->update(['read_at' => now()]);
    }

    /**
     * Moderation, for the Control Panel. These three do not go through
     * {@see ConversationPolicy}: the caller is not a party to the conversation
     * and authorises with the `manage messaging` permission instead.
     *
     * Only a message of a reported conversation (a participant flagged it) can
     * be hidden, so the Control Panel cannot be used to censor at will.
     */
    public function hideMessage(Message $message, string $reason): Message
    {
        $reason = trim(strip_tags($reason));

        if ($reason === '' || mb_strlen($reason) > self::MAX_REASON_LENGTH) {
            throw MessagingException::invalidReason(self::MAX_REASON_LENGTH);
        }

        if (! $message->conversation->participants()->where('flagged', true)->exists()) {
            throw MessagingException::notReported();
        }

        $message->update(['hidden' => true, 'hidden_reason' => $reason, 'hidden_at' => now()]);
        Realtime::announce(MessageBroadcast::HIDDEN, $message);

        return $message;
    }

    public function unhideMessage(Message $message): Message
    {
        $message->update(['hidden' => false, 'hidden_reason' => null, 'hidden_at' => null]);
        Realtime::announce(MessageBroadcast::RESTORED, $message);

        return $message;
    }

    public function unblock(ConversationParticipant $participant): ConversationParticipant
    {
        $participant->update(['blocked' => false]);

        return $participant;
    }

    /** A team member who has not touched the conversation yet gets their own row on first write. */
    protected function participant(Conversation $conversation, mixed $user): ConversationParticipant
    {
        return $conversation->participantOf($user)
            ?? $conversation->participants()->firstOrCreate(['user_id' => Users::key($user)], ['role' => ConversationParticipant::ROLE_MEMBER]);
    }

    protected function store(Conversation $conversation, string $senderKey, string $body): Message
    {
        $message = $conversation->messages()->create(['sender_user_id' => $senderKey, 'body' => $body]);
        $conversation->update(['last_message_at' => $message->created_at]);

        MessageSent::dispatch($message);

        return $message;
    }

    protected function clean(string $body): string
    {
        $body = trim(strip_tags($body));

        return match (true) {
            $body === '' => throw MessagingException::emptyBody(),
            mb_strlen($body) > self::MAX_BODY_LENGTH => throw MessagingException::bodyTooLong(self::MAX_BODY_LENGTH),
            default => $body,
        };
    }
}
