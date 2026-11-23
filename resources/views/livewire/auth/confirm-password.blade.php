<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.auth')] class extends Component {
    public string $password = '';

    /**
     * Confirm the current user's password.
     */
    public function confirmPassword(): void
    {
        $this->validate([
            'password' => ['required', 'string'],
        ]);

        if (! Auth::guard('web')->validate([
            'email' => Auth::user()->email,
            'password' => $this->password,
        ])) {
            throw ValidationException::withMessages([
                'password' => __('auth.password'),
            ]);
        }

        session(['auth.password_confirmed_at' => time()]);

        $this->redirectIntended(default: route('dashboard', absolute: false), navigate: true);
    }
}; ?>

<div class="flex flex-1 flex-col">
    <x-auth.brand :name="false" />

    <form wire:submit="confirmPassword" class="flex flex-col gap-5 px-6 pt-7 pb-10">
        <x-auth.heading title="Confirm password" description="This is a secure area of the application. Please confirm your password before continuing." />

        <x-auth.field wire:model="password" name="password" label="Password" type="password" required autocomplete="current-password" placeholder="Password" />

        <x-auth.button>{{ __('Confirm') }}</x-auth.button>
    </form>
</div>