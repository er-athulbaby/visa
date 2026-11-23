@php
    use App\Enums\ApplicantStatus;
    use App\Enums\PaymentStatus;

    $gray = 'bg-[#F3F4F6] text-[#374151]';
    $badge = [
        'draft' => $gray, 'pending_payment' => $gray,
        'submitted' => 'bg-[#EFF6FF] text-[#1D4ED8]',
        'in_review' => 'bg-[#EEF2FF] text-[#3730A3]', 'applied' => 'bg-[#EEF2FF] text-[#3730A3]',
        'needs_correction' => 'bg-[#FFFBEB] text-[#92400E]',
        'visa_issued' => 'bg-[#ECFDF5] text-[#065F46]',
        'rejected' => 'bg-[#FEF2F2] text-[#B91C1C]',
    ];
    $payBadge = [
        'initiated' => $gray, 'paid' => 'bg-[#ECFDF5] text-[#065F46]',
        'failed' => 'bg-[#FEF2F2] text-[#B91C1C]', 'refunded' => 'bg-[#FFFBEB] text-[#92400E]',
    ];
    $pill = 'inline-block whitespace-nowrap rounded-full px-2.5 py-0.5 text-xs font-semibold';
    $card = 'rounded-2xl border border-gray-200 bg-white';
    $h2 = 'font-heading text-xl font-extrabold';
    $cols = 'lg:grid lg:grid-cols-[2.5rem_1.5fr_1fr_0.7fr_1.3fr_1.3fr] lg:items-center lg:gap-x-2';
    $action = 'inline-flex min-h-11 items-center text-sm font-semibold text-[#B0102A] hover:text-[#7F0B1E]';
@endphp

<div class="mx-auto flex max-w-6xl flex-col gap-6 p-4 pb-8 lg:flex-row lg:items-start lg:p-8">
    <aside class="flex flex-col gap-4 lg:w-80 lg:flex-none">
        <h2 class="{{ $h2 }}">Open events</h2>
        @forelse ($events as $event)
            <article class="{{ $card }} overflow-hidden">
                <div class="h-1.5 bg-[#C8102E]"></div>
                <div class="flex flex-col gap-3.5 p-4">
                    <div class="flex flex-col gap-1">
                        <h3 class="text-lg font-bold">{{ $event->name }}</h3>
                        <p class="text-sm text-gray-600">{{ $event->start_date->format('d M Y') }} – {{ $event->end_date->format('d M Y') }}</p>
                    </div>
                    <dl class="grid grid-cols-2 gap-2">
                        <div class="rounded-[10px] bg-gray-50 px-3 py-2.5">
                            <dt class="text-xs text-gray-600">Visa fee</dt>
                            <dd class="mt-0.5 text-[15px] font-bold">{{ $event->currency }} {{ number_format($event->visa_fee, 3) }} <span class="text-xs font-normal text-gray-600">/ person</span></dd>
                        </div>
                        <div class="rounded-[10px] bg-gray-50 px-3 py-2.5">
                            <dt class="text-xs text-gray-600">Apply by</dt>
                            <dd class="mt-0.5 text-[15px] font-bold">{{ $event->application_deadline->format('d M Y') }}</dd>
                        </div>
                    </dl>
                    <a href="{{ route('applicants.create', $event) }}" wire:navigate class="flex h-12 items-center justify-center gap-2 rounded-xl bg-[#C8102E] text-[15px] font-bold text-white hover:bg-[#B0102A]">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"></path></svg>
                        Add applicant
                    </a>
                </div>
            </article>
        @empty
            <p class="rounded-2xl border border-dashed border-gray-300 bg-white p-4 text-sm text-gray-600">No events are open for applications right now.</p>
        @endforelse

        @if ($payments->isNotEmpty())
            <section class="{{ $card }} flex flex-col gap-3 p-4">
                <h2 class="text-base font-bold">Invoices</h2>
                <ul class="flex flex-col divide-y divide-gray-100">
                    @foreach ($payments as $p)
                        <li class="flex flex-col gap-1 py-3 first:pt-0 last:pb-0">
                            <div class="flex items-center justify-between gap-2">
                                <span class="text-[15px] font-bold">{{ $p->currency }} {{ number_format($p->amount, 3) }}</span>
                                <span class="{{ $pill }} {{ $payBadge[$p->status->value] }}">{{ $p->status->label() }}</span>
                            </div>
                            <span class="text-[13px] text-gray-600">{{ $p->event->name }} · {{ $p->created_at->format('d M Y') }} · {{ $p->applicants_count }} {{ Str::plural('applicant', $p->applicants_count) }}</span>
                            @if (Route::has('invoices.download'))
                                @foreach ($p->invoices as $invoice)
                                    <a href="{{ route('invoices.download', $invoice) }}" class="{{ $action }}">{{ $invoice->type === 'credit_note' ? 'Credit note' : 'Invoice' }} {{ $invoice->number }}</a>
                                @endforeach
                            @endif
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif
    </aside>

    <section class="flex min-w-0 flex-1 flex-col gap-4">
        <h2 class="{{ $h2 }}">My applicants</h2>
        @error('selected') <p class="rounded-xl bg-[#FEF2F2] px-4 py-3 text-sm text-[#B91C1C]" role="alert">{{ $message }}</p> @enderror

        @forelse ($applicantsByEvent as $eventId => $applicants)
            @php
                $event = $applicants->first()->event;
                $sel = $selection[$eventId];
                $hasDraft = $applicants->contains('status', ApplicantStatus::Draft);
            @endphp
            <div class="{{ $card }} flex flex-col">
                <div class="order-1 flex flex-col gap-0.5 border-b border-gray-200 px-4 py-4 lg:px-5">
                    <h3 class="font-heading text-lg font-extrabold">{{ $event->name }}</h3>
                    <span class="text-[13px] text-gray-600">{{ $applicants->count() }} {{ Str::plural('applicant', $applicants->count()) }}</span>
                </div>

                <ul class="order-2 lg:order-3">
                    <li aria-hidden="true" class="{{ $cols }} hidden bg-gray-50 px-5 py-3 text-sm font-semibold text-gray-700">
                        <span></span><span>Name</span><span>Passport</span><span>Nationality</span><span>Status</span><span class="text-right">Action</span>
                    </li>
                    @foreach ($applicants as $a)
                        @php $s = $a->status; @endphp
                        <li wire:key="applicant-{{ $a->id }}" class="{{ $cols }} flex flex-wrap items-start gap-x-3 gap-y-1 border-t border-gray-100 px-4 py-3.5 first:border-t-0 lg:px-5">
                            <span class="flex size-11 flex-none items-center justify-center lg:size-auto">
                                @if ($s === ApplicantStatus::Draft)
                                    <input type="checkbox" wire:model.live="selected" value="{{ $a->id }}" aria-label="Select {{ $a->full_name }}" class="size-5 accent-[#C8102E]">
                                @endif
                            </span>
                            <div class="min-w-0 flex-1 lg:flex-none">
                                <div class="font-semibold">{{ $a->full_name }}</div>
                                <div class="text-[13px] text-gray-600 lg:hidden">{{ $a->passport_number }} · {{ $a->nationality }}</div>
                            </div>
                            <span class="hidden text-gray-700 lg:block">{{ $a->passport_number }}</span>
                            <span class="hidden text-gray-700 lg:block">{{ $a->nationality }}</span>
                            <div class="flex flex-col items-start gap-1">
                                <span class="{{ $pill }} {{ $badge[$s->value] }}">{{ $s->label() }}</span>
                                @if ($s === ApplicantStatus::NeedsCorrection && $a->correction_note)
                                    <p class="text-[13px] text-[#92400E]">{{ $a->correction_note }}</p>
                                @elseif ($s === ApplicantStatus::Rejected && $a->rejection_reason)
                                    <p class="text-[13px] text-[#B91C1C]">{{ $a->rejection_reason }}</p>
                                @endif
                            </div>
                            <div class="flex w-full flex-wrap items-center gap-x-4 lg:w-auto lg:justify-end">
                                @if ($s->isEditableByApplicant())
                                    <a href="{{ route('applicants.edit', $a) }}" wire:navigate class="{{ $action }}">{{ $s === ApplicantStatus::NeedsCorrection ? 'Fix and resubmit' : 'Edit' }}</a>
                                @endif
                                @if ($s === ApplicantStatus::Draft)
                                    <button type="button" wire:click="delete({{ $a->id }})" wire:confirm="Delete this applicant?" class="inline-flex min-h-11 items-center text-sm font-semibold text-[#B91C1C]">Delete</button>
                                @endif
                                @if ($s === ApplicantStatus::VisaIssued)
                                    <a href="{{ route('applicant.file', [$a, 'visa']) }}" class="{{ $action }}">Download visa</a>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>

                @if ($hasDraft)
                    @includeIf('livewire.partials.pay-button', ['event' => $event, 'sel' => $sel])
                @endif
            </div>
        @empty
            <p class="rounded-2xl border border-dashed border-gray-300 bg-white p-6 text-center text-sm text-gray-600">You haven't added any applicants yet.</p>
        @endforelse
    </section>
</div>
