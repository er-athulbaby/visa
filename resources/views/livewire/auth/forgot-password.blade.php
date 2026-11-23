<?php

use Illuminate\Support\Facades\Password;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.auth')] class extends Component {
    public string $email = '';

    /**
     * Send a password reset link to the provided email address.
     */
    public function sendPasswordResetLink(): void
    {
        $this->validate([
            'email' => ['required', 'string', 'email'],
        ]);

        Password::sendResetLink($this->only('email'));

        session()->flash('status', __('A reset link will be sent if the account exists.'));
    }
}; ?>

<div class="flex flex-1 flex-col">
    <div class="px-1 pt-2">
        <a href="{{ route('login') }}" wire:navigate aria-label="Back to log in" class="flex size-11 items-center justify-center text-gray-900">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 18l-6-6 6-6"></path></svg>
        </a>
    </div>
    <x-auth.brand :name="false" />

    <form wire:submit="sendPasswordResetLink" class="flex flex-col gap-5 px-6 pt-7 pb-10">
        <x-auth.heading title="Forgot password?" description="Enter the email you registered with and we'll send you a link to choose a new password." />

        <x-auth.field wire:model="email" name="email" label="Email" type="email" required autofocus placeholder="you@example.com" />

        <x-auth.button>{{ __('Email password reset link') }}</x-auth.button>

        <x-auth.status tone="info" :status="session('status')" />

        <p class="text-center text-[15px] text-gray-700">Remembered it? <a href="{{ route('login') }}" wire:navigate class="font-semibold text-[#B0102A] hover:text-[#7F0B1E]">Back to log in</a></p>
    </form>
</div>