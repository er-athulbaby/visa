<div class="mx-auto max-w-2xl space-y-6 p-6">
    <h1 class="text-2xl font-semibold">
        {{ $applicant ? 'Edit applicant' : 'Add applicant' }} — {{ $event->name }}
    </h1>

    @if ($applicant?->correction_note && $applicant->status === \App\Enums\ApplicantStatus::NeedsCorrection)
        <div class="rounded border border-amber-300 bg-amber-50 p-4 text-amber-900">
            <strong>Correction requested:</strong> {{ $applicant->correction_note }}
        </div>
    @endif

    <form wire:submit="save" class="space-y-4">
        <div>
            <label class="block font-medium">Passport bio page (JPG, PNG or PDF, max 5 MB)</label>
            <input type="file" wire:model="passportScan" accept=".jpg,.jpeg,.png,.pdf">
            <div wire:loading wire:target="passportScan" class="text-sm text-gray-500">Reading passport…</div>
            @if ($ocrMessage) <p class="text-sm text-blue-700">{{ $ocrMessage }}</p> @endif
            @error('passportScan') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

        @foreach ([
            'full_name' => ['Full name', 'text'], 'email' => ['Email', 'email'],
            'date_of_birth' => ['Date of birth', 'date'], 'passport_number' => ['Passport number', 'text'],
            'passport_issue_date' => ['Date of issue', 'date'], 'passport_expiry_date' => ['Date of expiry', 'date'],
            'mobile' => ['Mobile', 'tel'], 'whatsapp' => ['WhatsApp', 'tel'],
        ] as $field => [$label, $type])
            <div>
                <label class="block font-medium" for="{{ $field }}">{{ $label }}</label>
                <input id="{{ $field }}" type="{{ $type }}" wire:model="form.{{ $field }}" class="w-full rounded border p-2">
                @error("form.$field") <p class="text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
        @endforeach

        @foreach (['nationality' => 'Nationality', 'issuing_country' => 'Country of issue'] as $field => $label)
            <div>
                <label class="block font-medium" for="{{ $field }}">{{ $label }}</label>
                <select id="{{ $field }}" wire:model="form.{{ $field }}" class="w-full rounded border p-2">
                    <option value="">Select…</option>
                    @foreach ($countries as $c)
                        <option value="{{ $c['alpha3'] }}">{{ $c['name'] }}</option>
                    @endforeach
                </select>
                @error("form.$field") <p class="text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
        @endforeach

        <div>
            <label class="block font-medium">Personal photo (white background, JPG or PNG)</label>
            <input type="file" wire:model="photo" accept=".jpg,.jpeg,.png">
            @error('photo') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

        <button type="submit" class="rounded bg-red-700 px-4 py-2 text-white" wire:loading.attr="disabled">
            {{ $applicant?->status === \App\Enums\ApplicantStatus::NeedsCorrection ? 'Resubmit' : 'Save' }}
        </button>
    </form>
</div>
