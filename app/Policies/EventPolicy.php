<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Event;
use App\Models\User;

class EventPolicy
{
    public function viewAny(User $user): bool { return $user->hasRole(Role::Admin); }

    public function view(User $user, Event $event): bool { return $user->hasRole(Role::Admin); }

    public function create(User $user): bool { return $user->hasRole(Role::Admin); }

    public function update(User $user, Event $event): bool { return $user->hasRole(Role::Admin); }

    public function delete(User $user, Event $event): bool { return false; } // close events instead

    public function deleteAny(User $user): bool { return false; }
}
