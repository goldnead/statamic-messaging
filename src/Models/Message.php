<?php

namespace Goldnead\Messaging\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $conversation_id
 * @property string|null $sender_user_id
 * @property string $body
 * @property Carbon|null $read_at
 * @property bool $hidden
 * @property string|null $hidden_reason
 * @property Carbon|null $hidden_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Message extends Model
{
    protected $table = 'messages';

    protected $guarded = ['id'];

    protected $casts = [
        'read_at' => 'datetime',
        'hidden' => 'boolean',
        'hidden_at' => 'datetime',
    ];

    /** @return BelongsTo<Conversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
