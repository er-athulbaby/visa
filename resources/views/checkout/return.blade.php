<x-layouts.app :title="__('Payment')">
    <div class="mx-auto max-w-xl space-y-4 p-6 text-center">
        @switch($payment->status)
            @case(\App\Enums\PaymentStatus::Paid)
                <h1 class="text-2xl font-semibold">Payment received</h1>
                <p>Thank you. Your invoice has been emailed to you and your applications are now in our queue.</p>
                @break
            @case(\App\Enums\PaymentStatus::Failed)
                <h1 class="text-2xl font-semibold">Payment did not go through</h1>
                <p>Your applicants are back in draft. You can try again from your dashboard.</p>
                @break
            @default
                <h1 class="text-2xl font-semibold">Confirming your payment…</h1>
                <p>This usually takes a few seconds. Refresh this page or check your dashboard shortly.</p>
        @endswitch
        <a href="{{ route('dashboard') }}" class="underline">Back to dashboard</a>
    </div>
</x-layouts.app>
