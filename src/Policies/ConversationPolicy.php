<?php

namespace Goldnead\Messaging\Policies;

use Goldnead\Messaging\Models\Conversation;
use Goldnead\Teams\Support\Users;

/**
 * Access is the initiator plus whoever the conversation was addressed to: the
 * recipient person, or the members of the recipient team *as of now*. A team
 * admin of some other team, or an admin of this team's CP, gets nothing from
 * their role, and a member who has left the team loses the thread even though
 * a participant row from their time in the team remains.
 */
class ConversationPolicy
{
    public function view(mixed $user, Conversation $conversation): bool
    {
        $key = Users::key($user);

        if ($key === null) {
            return false;
        }

        if ($conversation->isInitiator($user)) {
            return true;
        }

        if ($conversation->recipient_user_id !== null) {
            return $conversation->recipient_user_id === $key;
        }

        if ($conversation->recipient_team_id !== null) {
            return $conversation->recipientTeam?->hasMember($user) === true;
        }

        // Neither recipient set (the person's key was erased with their account):
        // only a remaining participant row can still read.
        return $conversation->participantOf($user) !== null;
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
