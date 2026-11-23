<?php

use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.auth')] class extends Component {
    public string $name = '';
    public string $email = '';
    public string $password = '';
    public string $password_confirmation = '';

    /**
     * Handle an incoming registration request.
     */
    public function register(): void
    {
        $key = 'register|'.request()->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'email' => 'Too many registration attempts. Please try again in a minute.',
            ]);
        }

        RateLimiter::hit($key, 60);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:' . User::class],
            'password' => ['required', 'string', 'confirmed', Rules\Password::defaults()],
        ]);

        $validated['password'] = Hash::make($validated['password']);

        event(new Registered(($user = User::create($validated))));

        Auth::login($user);

        $this->redirect(route('dashboard', absolute: false), navigate: true);
    }
}; ?>

<div class="flex flex-1 flex-col">
    <x-auth.brand />

    <form wire:submit="register" class="flex flex-col gap-[18px] px-6 pt-7 pb-10">
        <x-auth.heading title="Create account" description="One account can apply for several people — for example a team manager applying for a whole delegation." />

        <x-auth.status :status="session('status')" />

        <x-auth.field wire:model="name" name="name" label="Your full name" required autofocus autocomplete="name" placeholder="Name of the account holder" />
        <x-auth.field wire:model="email" name="email" label="Email" type="email" required autocomplete="email" placeholder="you@example.com" hint="We send status updates and the visa here." />
        <x-auth.field wire:model="password" name="password" label="Password" type="password" required autocomplete="new-password" placeholder="At least 8 characters" />
        <x-auth.field wire:model="password_confirmation" name="password_confirmation" label="Confirm password" type="password" required autocomplete="new-password" placeholder="Type it again" />

        <x-auth.button>Create account</x-auth.button>

        <p class="text-center text-[15px] text-gray-700">Already registered? <a href="{{ route('login') }}" wire:navigate class="font-semibold text-[#B0102A] hover:text-[#7F0B1E]">Log in</a></p>
    </form>

    <x-auth.privacy />
</div>
