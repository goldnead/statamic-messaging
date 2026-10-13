<?php

namespace Goldnead\Messaging\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $conversation_id
 * @property string $user_id
 * @property string $role
 * @property bool $archived
 * @property bool $blocked
 * @property bool $flagged
 */
class ConversationParticipant extends Model
{
    public const ROLE_INITIATOR = 'initiator';

    public const ROLE_MEMBER = 'member';

    protected $table = 'conversation_participants';

    protected $guarded = ['id'];

    protected $casts = [
        'archived' => 'boolean',
        'blocked' => 'boolean',
        'flagged' => 'boolean',
    ];

    /** @return BelongsTo<Conversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
