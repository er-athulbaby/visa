@php
    $correcting = $applicant?->status === \App\Enums\ApplicantStatus::NeedsCorrection;
    $input = 'h-12 w-full min-w-0 rounded-[10px] border border-gray-300 bg-white px-3 text-base text-gray-900';
    $label = 'text-sm font-semibold text-gray-900';
    $card = 'flex flex-col gap-3.5 rounded-2xl border border-gray-200 bg-white p-4';
    $badge = 'flex size-[26px] items-center justify-center rounded-full bg-[#C8102E] text-[13px] font-bold text-white';
    $err = 'text-sm text-red-600';
    $upload = 'mt-2 block w-full text-sm file:mr-3 file:h-11 file:rounded-xl file:border file:border-gray-300 file:bg-white file:px-4 file:font-semibold';
@endphp

<div class="min-h-screen bg-[#F6F6F7] font-sans text-gray-900">
    <header class="flex items-center gap-1 border-b border-gray-200 bg-white py-2 pr-2 pl-1">
        <a href="{{ route('dashboard') }}" wire:navigate aria-label="Back to dashboard" class="flex size-11 items-center justify-center text-gray-900">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 18l-6-6 6-6"></path></svg>
        </a>
        <div class="flex flex-col">
            <h1 class="font-heading text-base font-extrabold">{{ $correcting ? 'Fix application' : ($applicant ? 'Edit applicant' : 'Add applicant') }}</h1>
            <span class="text-xs text-gray-600">{{ $event->name }}</span>
        </div>
    </header>

    <div class="mx-auto max-w-2xl px-4 pt-5 pb-8">
        @if ($correcting && $applicant->correction_note)
            <section class="mb-5 flex flex-col gap-2.5 rounded-2xl border border-amber-200 bg-amber-50 p-4" role="alert">
                <div class="flex items-center gap-2.5">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#92400E" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3l9.5 16.5h-19z"></path><path d="M12 10v4M12 17h.01"></path></svg>
                    <h2 class="text-base font-bold text-amber-900">Our team asked for a correction</h2>
                </div>
                <p class="text-[15px] leading-normal text-amber-900"><strong>Correction requested:</strong> {{ $applicant->correction_note }}</p>
                <span class="text-xs text-amber-800">No extra payment needed</span>
            </section>
        @endif

        <form wire:submit="save" class="flex flex-col gap-5">
            <section class="{{ $card }}">
                <div class="flex items-center gap-2.5">
                    <span class="{{ $badge }}">1</span>
                    <h2 class="text-base font-bold">Passport bio page</h2>
                </div>
                <div>
                    <label class="{{ $label }}" for="passportScan">Passport bio page (JPG, PNG or PDF, max 5 MB)</label>
                    <input id="passportScan" type="file" wire:model="passportScan" accept=".jpg,.jpeg,.png,.pdf" class="{{ $upload }}">
                </div>
                @if ($passportScan)
                    <div class="flex flex-col gap-0.5 rounded-xl border border-gray-200 p-2.5 text-sm">
                        <span class="truncate font-semibold">{{ $passportScan->getClientOriginalName() }}</span>
                        <span class="text-[13px] text-gray-600">{{ number_format($passportScan->getSize() / 1048576, 1) }} MB</span>
                    </div>
                    @if ($passportScan->getMimeType() !== 'application/pdf')
                        <button type="button" wire:click="readPassport" wire:loading.attr="disabled" wire:target="readPassport" class="flex h-12 items-center justify-center gap-2 rounded-xl border-[1.5px] border-[#C8102E] bg-white text-[15px] font-bold text-[#9F1239]">
                            Read details from passport
                        </button>
                    @endif
                @endif
                <div wire:loading wire:target="passportScan,readPassport" class="text-sm text-gray-500">Reading passport…</div>
                @if ($ocrMessage)
                    <div role="status" class="flex items-start gap-2.5 rounded-[10px] border px-3 py-2.5 text-sm leading-snug {{ $ocrRaw ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-gray-200 bg-gray-50 text-gray-700' }}">
                        @if ($ocrRaw)
                            <svg class="mt-px flex-none" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6L9 17l-5-5"></path></svg>
                        @endif
                        <span>{{ $ocrMessage }}</span>
                    </div>
                @endif
                @error('passportScan') <p class="{{ $err }}">{{ $message }}</p> @enderror
            </section>

            <section class="{{ $card }}">
                <div class="flex items-center gap-2.5">
                    <span class="{{ $badge }}">2</span>
                    <h2 class="text-base font-bold">Check details</h2>
                </div>

                <div class="flex flex-col gap-1.5">
                    <label class="{{ $label }}" for="full_name">Full name <span class="font-medium text-gray-600">(as in passport)</span></label>
                    <input id="full_name" type="text" wire:model="form.full_name" class="{{ $input }}">
                    @error('form.full_name') <p class="{{ $err }}">{{ $message }}</p> @enderror
                </div>

                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    @foreach (['nationality' => 'Nationality', 'issuing_country' => 'Country of issue'] as $field => $text)
                        <div class="flex flex-col gap-1.5">
                            <label class="{{ $label }}" for="{{ $field }}">{{ $text }}</label>
                            <select id="{{ $field }}" wire:model="form.{{ $field }}" class="{{ $input }}">
                                <option value="">Select…</option>
                                @foreach ($countries as $c)
                                    <option value="{{ $c['alpha3'] }}">{{ $c['name'] }}</option>
                                @endforeach
                            </select>
                            @error("form.$field") <p class="{{ $err }}">{{ $message }}</p> @enderror
                        </div>
                    @endforeach
                    <div class="flex flex-col gap-1.5">
                        <label class="{{ $label }}" for="date_of_birth">Date of birth</label>
                        <input id="date_of_birth" type="date" wire:model="form.date_of_birth" class="{{ $input }}">
                        @error('form.date_of_birth') <p class="{{ $err }}">{{ $message }}</p> @enderror
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <label class="{{ $label }}" for="passport_number">Passport number</label>
                        <input id="passport_number" type="text" wire:model="form.passport_number" class="{{ $input }}">
                        @error('form.passport_number') <p class="{{ $err }}">{{ $message }}</p> @enderror
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <label class="{{ $label }}" for="passport_issue_date">Date of issue</label>
                        <input id="passport_issue_date" type="date" wire:model="form.passport_issue_date" class="{{ $input }} border-2 !border-[#C8102E]">
                        <span class="text-xs text-gray-600">Not on the MRZ — please type it</span>
                        @error('form.passport_issue_date') <p class="{{ $err }}">{{ $message }}</p> @enderror
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <label class="{{ $label }}" for="passport_expiry_date">Date of expiry</label>
                        <input id="passport_expiry_date" type="date" wire:model="form.passport_expiry_date" class="{{ $input }}">
                        <span class="text-xs text-gray-600">Valid 6+ months after the event</span>
                        @error('form.passport_expiry_date') <p class="{{ $err }}">{{ $message }}</p> @enderror
                    </div>
                </div>
            </section>

            <section class="{{ $card }}">
                <div class="flex items-center gap-2.5">
                    <span class="{{ $badge }}">3</span>
                    <h2 class="text-base font-bold">Contact</h2>
                </div>

                <div class="flex flex-col gap-1.5">
                    <label class="{{ $label }}" for="email">Email</label>
                    <input id="email" type="email" wire:model="form.email" placeholder="applicant@example.com" class="{{ $input }}">
                    @error('form.email') <p class="{{ $err }}">{{ $message }}</p> @enderror
                </div>

                @foreach (['mobile' => 'Mobile', 'whatsapp' => 'WhatsApp'] as $key => $text)
                    @continue($key === 'whatsapp' && $sameAsMobile)
                    <fieldset class="flex flex-col gap-1.5">
                        <legend class="mb-1.5 {{ $label }}">{{ $text }}</legend>
                        <div class="flex gap-2">
                            <label for="{{ $key }}Region" class="sr-only">{{ $text }} country code</label>
                            <select id="{{ $key }}Region" wire:model="{{ $key }}Region" class="{{ $input }} !w-[118px] flex-none !px-2">
                                <option value="">Select</option>
                                @foreach ($phoneCodes as $code)
                                    <option value="{{ $code['region'] }}">{{ $code['label'] }}</option>
                                @endforeach
                            </select>
                            <label for="{{ $key }}Number" class="sr-only">{{ $text }} number</label>
                            <input id="{{ $key }}Number" type="tel" inputmode="numeric" wire:model="{{ $key }}Number" class="{{ $input }} flex-1">
                        </div>
                        @error("{$key}Number") <p class="{{ $err }}">{{ $message }}</p> @enderror
                    </fieldset>
                @endforeach

                <label class="flex min-h-11 items-center gap-2.5 text-sm text-gray-700">
                    <input type="checkbox" wire:model.live="sameAsMobile" class="size-5 accent-[#C8102E]">
                    Same as mobile
                </label>
            </section>

            <section class="{{ $card }}">
                <div class="flex items-center gap-2.5">
                    <span class="{{ $badge }}">4</span>
                    <h2 class="text-base font-bold">Personal photo</h2>
                </div>
                <div class="flex items-center gap-3.5">
                    <div class="flex h-[108px] w-[84px] flex-none items-center justify-center rounded-[10px] border-[1.5px] border-dashed border-gray-400">
                        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#6B7280" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="8" r="4"></circle><path d="M4 21c1.5-4 4.5-6 8-6s6.5 2 8 6"></path></svg>
                    </div>
                    <ul class="list-disc space-y-1 pl-[18px] text-[13px] leading-snug text-gray-700">
                        <li>Plain white background</li>
                        <li>Face centred, looking straight ahead</li>
                        <li>No glasses or hat</li>
                        <li>JPG or PNG, max 5 MB</li>
                    </ul>
                </div>
                <div>
                    <label class="{{ $label }}" for="photo">Personal photo (white background, JPG or PNG)</label>
                    <input id="photo" type="file" wire:model="photo" accept=".jpg,.jpeg,.png" class="{{ $upload }}">
                </div>
                @error('photo') <p class="{{ $err }}">{{ $message }}</p> @enderror
            </section>

            <div>
                <label class="flex cursor-pointer items-start gap-3 rounded-[14px] border border-gray-200 bg-white px-4 py-3.5 text-sm leading-normal text-gray-700">
                    <input type="checkbox" wire:model="confirmed" class="mt-px size-[22px] flex-none accent-[#C8102E]">
                    <span>I confirm the details above match the passport and are correct. I understand wrong details can delay or cancel the visa.</span>
                </label>
                @error('confirmed') <p class="mt-1 {{ $err }}">{{ $message }}</p> @enderror
            </div>

            <button type="submit" wire:loading.attr="disabled" class="h-[52px] rounded-xl bg-[#C8102E] text-base font-bold text-white hover:bg-[#B0102A] disabled:opacity-60">
                {{ $correcting ? 'Resubmit application' : 'Save applicant' }}
            </button>
            @unless ($correcting)
                <p class="text-center text-[13px] leading-normal text-gray-600">Saved applicants stay in draft until you pay the visa fee from your dashboard.</p>
            @endunless
        </form>
    </div>
</div>
