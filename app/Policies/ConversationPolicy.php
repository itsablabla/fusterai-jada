<?php

namespace App\Policies;

use App\Domains\Conversation\Models\Conversation;
use App\Models\User;

class ConversationPolicy
{
    /** Super-admins bypass all checks. */
    public function before(User $user, string $ability): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    /** Any workspace member can view a conversation, subject to mailbox access. */
    public function view(User $user, Conversation $conversation): bool
    {
        if ($user->workspace_id !== $conversation->workspace_id) {
            return false;
        }

        if ($user->isAdmin()) {
            return true;
        }

        // Non-admin agents with explicit mailbox assignments are restricted to those mailboxes.
        // Agents with no assignments can view all conversations (backward-compatible default).
        $allowedMailboxIds = $user->mailboxes()->pluck('mailboxes.id');

        return $allowedMailboxIds->isEmpty()
            || $allowedMailboxIds->contains($conversation->mailbox_id);
    }

    /**
     * Covers: reply, status change, priority, assign, snooze, merge, sync tags.
     * All roles (agent+) can perform these on conversations in their workspace.
     */
    public function update(User $user, Conversation $conversation): bool
    {
        return $this->view($user, $conversation);
    }
}
