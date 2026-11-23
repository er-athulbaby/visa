<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Payment;
use App\Models\User;

class PaymentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole(Role::Admin, Role::Finance);
    }

    public function view(User $user, Payment $payment): bool
    {
        return $payment->user_id === $user->id || $this->viewAny($user);
    }

    public function refund(User $user, Payment $payment): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool { return false; }

    public function update(User $user, Payment $payment): bool { return false; }

    public function delete(User $user, Payment $payment): bool { return false; }

    public function deleteAny(User $user): bool { return false; }
}
