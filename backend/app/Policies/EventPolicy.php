<?php

namespace App\Policies;

use App\Models\Event;
use App\Models\User;

class EventPolicy
{
    public function view(User $user, Event $event): bool { return $user->isMasterAdmin() || $user->hasPermission('checkins.manage') || (int) $event->created_by === (int) $user->id; }
    public function update(User $user, Event $event): bool { return $user->isMasterAdmin() || (int) $event->created_by === (int) $user->id; }
    public function delete(User $user, Event $event): bool { return $this->update($user, $event); }
}
