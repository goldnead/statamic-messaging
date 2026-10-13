<?php

namespace Goldnead\Messaging\Policies;

use Goldnead\Messaging\Models\Conversation;

/**
 * Access is the participant list plus the members of the recipient team.
 * A team admin of some other team, or an admin of this team's CP, gets
 * nothing from their role: only membership counts.
 */
class ConversationPolicy
{
    public function view(mixed $user, Conversation $conversation): bool
    {
        return $conversation->participantOf($user) !== null
            || ($conversation->recipient_team_id !== null && $conversation->recipientTeam?->hasMember($user) === true);
    }

    public function reply(mixed $user, Conversation $conversation): bool
    {
        return $this->view($user, $conversation) && ! $conversation->participantOf($user)?->blocked;
    }

    /** Only the anonymous side can reveal itself. */
    public function shareProfile(mixed $user, Conversation $conversation): bool
    {
        return $conversation->anonymous && ! $conversation->profile_shared && $conversation->isInitiator($user);
    }

    public function block(mixed $user, Conversation $conversation): bool
    {
        return $this->view($user, $conversation);
    }

    public function archive(mixed $user, Conversation $conversation): bool
    {
        return $this->view($user, $conversation);
    }
}
