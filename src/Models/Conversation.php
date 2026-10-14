<?php

namespace Goldnead\Messaging\Models;

use Goldnead\Teams\Models\Team;
use Goldnead\Teams\Support\Users;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string|null $subject_type
 * @property string|null $subject_id
 * @property int|null $recipient_team_id
 * @property string|null $recipient_user_id
 * @property bool $anonymous
 * @property bool $profile_shared
 * @property Carbon|null $last_message_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Conversation extends Model
{
    public const ANONYMOUS_LABEL = 'Anonyme:r Interessent:in';

    public const DELETED_LABEL = 'Konto gelöscht';

    protected $table = 'conversations';

    protected $guarded = ['id'];

    protected $casts = [
        'anonymous' => 'boolean',
        'profile_shared' => 'boolean',
        'last_message_at' => 'datetime',
    ];

    /** @return HasMany<ConversationParticipant, $this> */
    public function participants(): HasMany
    {
        return $this->hasMany(ConversationParticipant::class);
    }

    /** @return HasMany<Message, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /** @return BelongsTo<Team, $this> */
    public function recipientTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'recipient_team_id');
    }

    public function participantOf(mixed $user): ?ConversationParticipant
    {
        $key = Users::key($user);

        return $key === null ? null : $this->participants()->where('user_id', $key)->first();
    }

    public function isInitiator(mixed $user): bool
    {
        return $this->participantOf($user)?->role === ConversationParticipant::ROLE_INITIATOR;
    }

    /** The key of the person who started the conversation, `null` once their account is erased. */
    public function initiatorKey(): ?string
    {
        $key = $this->participants()->where('role', ConversationParticipant::ROLE_INITIATOR)->value('user_id');

        return $key === null ? null : (string) $key;
    }

    /** The initiator's identity is hidden while the request is anonymous and the profile is not released. */
    public function isMasked(): bool
    {
        return $this->anonymous && ! $this->profile_shared;
    }

    /**
     * What `$viewer` may see as the name of `$user`. Only the anonymous side
     * is ever masked, and never towards itself.
     *
     * Pass `$initiatorKey` when naming many senders of one thread, so the
     * initiator is looked up once instead of once per call.
     */
    public function displayName(mixed $user, mixed $viewer, ?string $initiatorKey = null): string
    {
        if ($this->isMasked()) {
            $initiatorKey ??= $this->initiatorKey();
            $userKey = Users::key($user);

            if ($initiatorKey !== null && $userKey === $initiatorKey && Users::key($viewer) !== $initiatorKey) {
                return self::ANONYMOUS_LABEL;
            }
        }

        return Users::name($user) ?? '';
    }
}
