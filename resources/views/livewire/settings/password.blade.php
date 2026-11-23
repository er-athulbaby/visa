<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Component;

new class extends Component {
    public string $current_password = '';
    public string $password = '';
    public string $password_confirmation = '';

    /**
     * Update the password for the currently authenticated user.
     */
    public function updatePassword(): void
    {
        try {
            $validated = $this->validate([
                'current_password' => ['required', 'string', 'current_password'],
                'password' => ['required', 'string', Password::defaults(), 'confirmed'],
            ]);
        } catch (ValidationException $e) {
            $this->reset('current_password', 'password', 'password_confirmation');

            throw $e;
        }

        Auth::user()->update([
            'password' => Hash::make($validated['password']),
        ]);

        $this->reset('current_password', 'password', 'password_confirmation');

        $this->dispatch('password-updated');
    }
}; ?>

<section class="w-full p-4 lg:p-8">
    @include('partials.settings-heading')

    <x-settings.layout heading="Update password" subheading="Ensure your account is using a long, random password to stay secure">
        <form wire:submit="updatePassword" class="flex flex-col gap-5">
            <x-auth.field wire:model="current_password" id="update_password_current_password" name="current_password" label="{{ __('Current password') }}" type="password" required autocomplete="current-password" />
            <x-auth.field wire:model="password" id="update_password_password" name="password" label="{{ __('New password') }}" type="password" required autocomplete="new-password" />
            <x-auth.field wire:model="password_confirmation" id="update_password_password_confirmation" name="password_confirmation" label="{{ __('Confirm Password') }}" type="password" required autocomplete="new-password" />

            <div class="flex items-center gap-4">
                <x-auth.button class="!w-auto px-8">{{ __('Save') }}</x-auth.button>

                <x-action-message class="me-3" on="password-updated">
                    {{ __('Saved.') }}
                </x-action-message>
            </div>
        </form>
    </x-settings.layout>
</section>