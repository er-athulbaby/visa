<?php

use Illuminate\Auth\Events\Lockout;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;

new #[Layout('components.layouts.auth')] class extends Component {
    #[Validate('required|string|email')]
    public string $email = '';

    #[Validate('required|string')]
    public string $password = '';

    public bool $remember = false;

    /**
     * Handle an incoming authentication request.
     */
    public function login(): void
    {
        $this->validate();

        $this->ensureIsNotRateLimited();

        if (! Auth::attempt(['email' => $this->email, 'password' => $this->password, 'role' => \App\Enums\Role::Applicant->value], $this->remember)) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        RateLimiter::clear($this->throttleKey());
        Session::regenerate();

        $this->redirectIntended(default: route('dashboard', absolute: false), navigate: true);
    }

    /**
     * Ensure the authentication request is not rate limited.
     */
    protected function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout(request()));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => __('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    /**
     * Get the authentication rate limiting throttle key.
     */
    protected function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->email).'|'.request()->ip());
    }
}; ?>

<div class="flex flex-1 flex-col">
    <x-auth.brand />

    <form wire:submit="login" class="flex flex-col gap-5 px-6 pt-10 pb-10">
        <x-auth.heading title="Log in" description="Apply for and track visas for athletes, officials and delegations attending BOC events." />

        <x-auth.status :status="session('status')" />

        <x-auth.field wire:model="email" name="email" label="Email" type="email" required autofocus autocomplete="email" placeholder="you@example.com" />

        <x-auth.field wire:model="password" name="password" label="Password" type="password" required autocomplete="current-password" placeholder="Your password">
            <x-slot:aside>
                @if (Route::has('password.request'))
                    <a href="{{ route('password.request') }}" wire:navigate class="text-sm font-medium text-[#B0102A] hover:text-[#7F0B1E]">Forgot password?</a>
                @endif
            </x-slot:aside>
        </x-auth.field>

        <label class="flex min-h-11 items-center gap-2.5 text-sm text-gray-700">
            <input type="checkbox" wire:model="remember" class="size-5 accent-[#C8102E]">
            Keep me logged in
        </label>

        <x-auth.button>Log in</x-auth.button>

        <p class="text-center text-[15px] text-gray-700">New here? <a href="{{ route('register') }}" wire:navigate class="font-semibold text-[#B0102A] hover:text-[#7F0B1E]">Create an account</a></p>
    </form>

    <x-auth.privacy />
</div>
