@php use App\Enums\ApplicantStatus; @endphp
<div class="mx-auto max-w-5xl space-y-8 p-6">
    <section>
        <h2 class="mb-3 text-xl font-semibold">Open events</h2>
        @forelse ($events as $event)
            <div class="flex items-center justify-between border-b py-2">
                <div>
                    <div class="font-medium">{{ $event->name }}</div>
                    <div class="text-sm text-gray-600">
                        {{ $event->start_date->format('d M Y') }} – {{ $event->end_date->format('d M Y') }}
                        · Visa fee {{ $event->currency }} {{ number_format($event->visa_fee, 3) }}
                        · Apply by {{ $event->application_deadline->format('d M Y') }}
                    </div>
                </div>
                <a href="{{ route('applicants.create', $event) }}" class="rounded bg-red-700 px-3 py-1 text-white">Add applicant</a>
            </div>
        @empty
            <p class="text-gray-600">No events are open for applications right now.</p>
        @endforelse
    </section>

    <section>
        <h2 class="mb-3 text-xl font-semibold">My applicants</h2>
        @error('selected') <p class="text-red-600">{{ $message }}</p> @enderror
        @forelse ($applicantsByEvent as $eventId => $applicants)
            @php $event = $applicants->first()->event; @endphp
            <div class="mb-6">
                <h3 class="font-medium">{{ $event->name }}</h3>
                <table class="w-full text-left text-sm">
                    <thead><tr><th></th><th>Name</th><th>Passport</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                    @foreach ($applicants as $a)
                        <tr class="border-b">
                            <td>
                                @if ($a->status === ApplicantStatus::Draft)
                                    <input type="checkbox" wire:model="selected" value="{{ $a->id }}">
                                @endif
                            </td>
                            <td>{{ $a->full_name }}</td>
                            <td>{{ $a->passport_number }}</td>
                            <td>
                                {{ $a->status->label() }}
                                @if ($a->status === ApplicantStatus::NeedsCorrection)
                                    <div class="text-amber-700">{{ $a->correction_note }}</div>
                                @elseif ($a->status === ApplicantStatus::Rejected)
                                    <div class="text-red-700">{{ $a->rejection_reason }}</div>
                                @endif
                            </td>
                            <td class="space-x-2 text-right">
                                @if ($a->status->isEditableByApplicant())
                                    <a href="{{ route('applicants.edit', $a) }}" class="underline">Edit</a>
                                @endif
                                @if ($a->status === ApplicantStatus::Draft)
                                    <button wire:click="delete({{ $a->id }})" wire:confirm="Delete this applicant?" class="text-red-700 underline">Delete</button>
                                @endif
                                @if ($a->status === ApplicantStatus::VisaIssued)
                                    <a href="{{ route('applicant.file', [$a, 'visa']) }}" class="font-medium underline">Download visa</a>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
                @if ($applicants->contains('status', ApplicantStatus::Draft))
                    @includeIf('livewire.partials.pay-button', ['event' => $event])
                @endif
            </div>
        @empty
            <p class="text-gray-600">You haven't added any applicants yet.</p>
        @endforelse
    </section>

    @if ($payments->isNotEmpty())
        <section>
            <h2 class="mb-3 text-xl font-semibold">Payments</h2>
            <table class="w-full text-left text-sm">
                <thead><tr><th>Date</th><th>Event</th><th>Amount</th><th>Status</th><th>Invoice</th></tr></thead>
                <tbody>
                @foreach ($payments as $p)
                    <tr class="border-b">
                        <td>{{ $p->created_at->format('d M Y') }}</td>
                        <td>{{ $p->event->name }}</td>
                        <td>{{ $p->currency }} {{ number_format($p->amount, 3) }}</td>
                        <td>{{ $p->status->label() }}</td>
                        <td>
                            @foreach ($p->invoices as $invoice)
                                @if (Route::has('invoices.download'))
                                    <a href="{{ route('invoices.download', $invoice) }}" class="underline">{{ $invoice->number }}</a>
                                @endif
                            @endforeach
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </section>
    @endif
</div>
