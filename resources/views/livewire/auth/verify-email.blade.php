<?php

use App\Livewire\Actions\Logout;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.auth')] class extends Component {
    /**
     * Send an email verification notification to the user.
     */
    public function sendVerification(): void
    {
        if (Auth::user()->hasVerifiedEmail()) {
            $this->redirectIntended(default: route('dashboard', absolute: false), navigate: true);

            return;
        }

        Auth::user()->sendEmailVerificationNotification();

        Session::flash('status', 'verification-link-sent');
    }

    /**
     * Log the current user out of the application.
     */
    public function logout(Logout $logout): void
    {
        $logout();

        $this->redirect('/', navigate: true);
    }
}; ?>

<div class="flex flex-1 flex-col">
    <x-auth.brand :name="false" />

    <div class="flex flex-col items-center gap-4 px-6 pt-12 text-center">
        <div class="flex size-[72px] items-center justify-center rounded-full bg-[#FEF2F2]">
            <svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="#B0102A" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"></rect><path d="M3 7l9 6 9-6"></path></svg>
        </div>
        <h1 class="font-heading text-[26px] font-extrabold">Check your email</h1>
        <p class="text-[15px] leading-relaxed text-gray-700">We sent a verification link to<br><strong class="text-gray-900">{{ auth()->user()->email }}</strong></p>
        <p class="text-sm leading-relaxed text-gray-600">{{ __('Please verify your email address by clicking on the link we just emailed to you.') }}</p>
    </div>

    @if (session('status') == 'verification-link-sent')
        <x-auth.status class="mx-6 mt-7" :status="__('A new verification link has been sent to the email address you provided during registration.')" />
    @endif

    <div class="mt-auto flex flex-col gap-2.5 px-6 pt-8 pb-8">
        <x-auth.button type="button" wire:click="sendVerification">{{ __('Resend verification email') }}</x-auth.button>
        <p class="text-center text-sm leading-normal text-gray-600">Didn't get it? Check your spam folder.</p>
        <button type="button" wire:click="logout" class="h-11 text-[15px] font-semibold text-gray-700 hover:text-gray-900">{{ __('Log out') }}</button>
    </div>
</div>
