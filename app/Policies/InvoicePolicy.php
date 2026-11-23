<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Invoice;
use App\Models\User;

class InvoicePolicy
{
    public function view(User $user, Invoice $invoice): bool
    {
        return $invoice->payment->user_id === $user->id || $user->hasRole(Role::Admin, Role::Finance);
    }
}
