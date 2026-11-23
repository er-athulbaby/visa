@php
    use App\Enums\PaymentStatus;

    $invoice = $payment->invoices()->latest('id')->first();
    $count = $payment->applicants()->count();
    $support = config('visa.support_email');
    $btn = 'flex h-[52px] items-center justify-center rounded-xl text-base font-bold';
    $primary = $btn.' bg-[#C8102E] text-white hover:bg-[#B0102A]';
    $secondary = 'flex h-12 items-center justify-center rounded-xl border-[1.5px] border-gray-300 text-[15px] font-semibold text-gray-900';
    $row = 'flex justify-between gap-4 border-b border-gray-100 py-3 last:border-b-0';
@endphp
<x-layouts.app :title="__('Payment')">
    <div class="mx-auto flex min-h-[70vh] max-w-md flex-col gap-7 px-6 py-10 lg:py-16">
        @switch($payment->status)
            @case(PaymentStatus::Paid)
                <div class="flex flex-col items-center gap-4 text-center">
                    <div class="flex size-[72px] items-center justify-center rounded-full bg-[#ECFDF5]">
                        <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="#065F46" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" role="img" aria-label="Success"><path d="M20 6L9 17l-5-5"></path></svg>
                    </div>
                    <h1 class="font-heading text-[26px] font-extrabold">Payment received</h1>
                    <p class="text-[15px] leading-normal text-gray-600">Thank you. Your applications are now in our queue and we will email you at each step.</p>
                </div>

                <dl class="rounded-2xl border border-gray-200 bg-white px-4 py-1 text-sm">
                    <div class="{{ $row }}"><dt class="text-gray-600">Event</dt><dd class="font-semibold">{{ $payment->event->name }}</dd></div>
                    <div class="{{ $row }}"><dt class="text-gray-600">Applicants</dt><dd class="font-semibold">{{ $count }}</dd></div>
                    <div class="{{ $row }}"><dt class="text-gray-600">Amount paid</dt><dd class="font-bold">{{ $payment->currency }} {{ number_format($payment->amount, 3) }}</dd></div>
                    @if ($invoice)
                        <div class="{{ $row }}"><dt class="text-gray-600">Invoice</dt><dd class="font-semibold">{{ $invoice->number }}</dd></div>
                    @endif
                </dl>

                <div class="mt-auto flex flex-col gap-2.5">
                    <a href="{{ route('dashboard') }}" wire:navigate class="{{ $primary }}">Back to dashboard</a>
                    @if ($invoice)
                        <a href="{{ route('invoices.download', $invoice) }}" class="{{ $secondary }}">Download invoice</a>
                    @endif
                </div>
                @break

            @case(PaymentStatus::Failed)
                <div class="flex flex-col items-center gap-4 text-center">
                    <div class="flex size-[72px] items-center justify-center rounded-full bg-[#FEF2F2]">
                        <svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="#B91C1C" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" role="img" aria-label="Failed"><path d="M18 6L6 18M6 6l12 12"></path></svg>
                    </div>
                    <h1 class="font-heading text-[26px] font-extrabold">Payment didn't go through</h1>
                    <p class="text-[15px] leading-normal text-gray-600">Your card was not charged. Your {{ $count }} {{ Str::plural('applicant', $count) }} {{ $count === 1 ? 'is' : 'are' }} back in draft, so nothing is lost — you can try again from your dashboard.</p>
                </div>

                <div class="flex flex-col gap-2.5 rounded-2xl border border-gray-200 bg-white p-4">
                    <h2 class="text-sm font-bold">Common reasons</h2>
                    <ul class="flex list-disc flex-col gap-1.5 pl-[18px] text-sm leading-snug text-gray-700">
                        <li>The bank declined the payment or 3-D Secure check</li>
                        <li>The payment page was closed before finishing</li>
                        <li>The card doesn't allow international payments</li>
                    </ul>
                </div>

                <div class="mt-auto flex flex-col gap-2.5">
                    <a href="{{ route('dashboard') }}" wire:navigate class="{{ $primary }}">Try again</a>
                    @if ($support)
                        <p class="text-center text-[13px] leading-normal text-gray-600">Need help? Contact <a href="mailto:{{ $support }}" class="font-semibold text-[#B0102A]">{{ $support }}</a></p>
                    @endif
                </div>
                @break

            @default
                <div role="status" class="flex flex-col items-center gap-4 pt-8 text-center">
                    <div class="flex size-[72px] items-center justify-center rounded-full bg-gray-100">
                        <svg class="animate-spin motion-reduce:animate-none" width="34" height="34" viewBox="0 0 24 24" fill="none" stroke-width="2.5" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9" stroke="#E5E7EB"></circle><path d="M21 12a9 9 0 0 0-9-9" stroke="#C8102E"></path></svg>
                    </div>
                    <h1 class="font-heading text-[26px] font-extrabold">Confirming your payment…</h1>
                    <p class="text-[15px] leading-normal text-gray-600">We're waiting for confirmation from the payment provider. This usually takes a few seconds.</p>
                </div>

                <div class="flex items-start gap-2.5 rounded-xl bg-gray-50 px-3.5 py-3">
                    <svg class="mt-px flex-none" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#4B5563" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"></circle><path d="M12 8v5M12 16h.01"></path></svg>
                    <p class="text-[13px] leading-normal text-gray-700">Please don't pay again. If you close this page, your dashboard will update on its own once the payment is confirmed.</p>
                </div>

                <div class="mt-auto flex flex-col gap-2.5">
                    <a href="{{ url()->current() }}" class="{{ $primary }}">Refresh status</a>
                    <a href="{{ route('dashboard') }}" wire:navigate class="{{ $secondary }}">Go to dashboard</a>
                </div>
        @endswitch
    </div>
</x-layouts.app>
