<?php

namespace App\Policies;

use App\Models\Event;
use App\Models\User;

class EventPolicy
{
    public function view(User $user, Event $event): bool { return $user->role === 'admin' || $event->created_by === $user->id; }
    public function update(User $user, Event $event): bool { return $this->view($user, $event); }
    public function delete(User $user, Event $event): bool { return $this->view($user, $event); }
}
