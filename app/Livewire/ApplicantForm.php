<?php

namespace App\Livewire;

use App\Enums\ApplicantStatus;
use App\Models\Applicant;
use App\Models\Event;
use App\Services\ImageSanitizer;
use App\Services\PassportOcr;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use League\ISO3166\ISO3166;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

class ApplicantForm extends Component
{
    use WithFileUploads;

    private const FIELDS = [
        'full_name', 'email', 'nationality', 'date_of_birth', 'passport_number', 'issuing_country',
        'passport_issue_date', 'passport_expiry_date',
    ];

    public Event $event;

    public ?Applicant $applicant = null;

    public array $form = [];

    public $passportScan = null;

    public $photo = null;

    public ?string $ocrMessage = null;

    #[Locked]
    public ?array $ocrRaw = null;

    public ?string $mobileRegion = null;

    public ?string $mobileNumber = null;

    public ?string $whatsappRegion = null;

    public ?string $whatsappNumber = null;

    public bool $sameAsMobile = false;

    public bool $confirmed = false;

    public function mount(?Event $event = null, ?Applicant $applicant = null): void
    {
        if ($applicant?->exists) {
            Gate::authorize('update', $applicant);
            $this->applicant = $applicant;
            $this->event = $applicant->event;
            foreach (self::FIELDS as $field) {
                $value = $applicant->{$field};
                $this->form[$field] = $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : $value;
            }
            [$this->mobileRegion, $this->mobileNumber] = $this->splitPhone($applicant->mobile);
            [$this->whatsappRegion, $this->whatsappNumber] = $this->splitPhone($applicant->whatsapp);

            return;
        }

        abort_unless($event?->exists && $event->isAcceptingApplications(), 403, 'This event is not accepting applications.');
        $this->event = $event;
        $this->form = array_fill_keys(self::FIELDS, null);
    }

    public function updatedPassportScan(): void
    {
        $this->validateOnly('passportScan');

        $this->ocrRaw = null;
        $this->ocrMessage = $this->isPdf() ? 'PDF uploaded. Please fill in the details.' : null;
    }

    public function readPassport(): void
    {
        $this->validateOnly('passportScan');

        if (! $this->passportScan || $this->isPdf()) {
            return;
        }

        $result = app(PassportOcr::class)->read($this->passportScan->getRealPath());

        if ($result === null) {
            $this->ocrMessage = "Couldn't read the passport automatically. Please fill in the details.";

            return;
        }

        $this->ocrRaw = $result;
        $this->form = array_merge($this->form, array_filter($result));
        $this->ocrMessage = 'Passport read. Please check every field.';
    }

    private function isPdf(): bool
    {
        return $this->passportScan?->getMimeType() === 'application/pdf';
    }

    /** @return array{0: ?string, 1: ?string} region and national number from an E.164 string */
    private function splitPhone(?string $e164): array
    {
        try {
            $number = PhoneNumberUtil::getInstance()->parse((string) $e164);
            $region = PhoneNumberUtil::getInstance()->getRegionCodeForNumber($number);

            return [$region, PhoneNumberUtil::getInstance()->getNationalSignificantNumber($number)];
        } catch (NumberParseException) {
            return [null, null];
        }
    }

    private function toE164(?string $region, ?string $number): ?string
    {
        if (! $region || ! $number) {
            return null;
        }
        try {
            $util = PhoneNumberUtil::getInstance();
            $parsed = $util->parse($number, $region);

            return $util->isValidNumberForRegion($parsed, $region) ? $util->format($parsed, PhoneNumberFormat::E164) : null;
        } catch (NumberParseException) {
            return null;
        }
    }

    private function phoneRule(string $regionProp, string $label): \Closure
    {
        return function ($attribute, $value, $fail) use ($regionProp, $label) {
            if ($this->toE164($this->{$regionProp}, $value) === null) {
                $fail("Enter a valid {$label} number for the selected country.");
            }
        };
    }

    protected function rules(): array
    {
        $countries = array_column((new ISO3166)->all(), 'alpha3');
        $editing = $this->applicant !== null;

        return [
            'form.full_name' => ['required', 'string', 'max:255'],
            'form.email' => ['required', 'email', 'max:255'],
            'form.nationality' => ['required', Rule::in($countries)],
            'form.issuing_country' => ['required', Rule::in($countries)],
            'form.date_of_birth' => ['required', 'date', 'before:today'],
            'form.passport_number' => ['required', 'string', 'max:20', 'regex:/^[A-Z0-9]+$/',
                Rule::unique('applicants', 'passport_number')->where('event_id', $this->event->id)->ignore($this->applicant?->id)],
            'form.passport_issue_date' => ['required', 'date', 'before_or_equal:today'],
            'form.passport_expiry_date' => ['required', 'date', 'after:form.passport_issue_date',
                'after_or_equal:'.$this->event->minimumPassportExpiry()->toDateString()],
            'mobileNumber' => ['required', $this->phoneRule('mobileRegion', 'mobile')],
            'whatsappNumber' => $this->sameAsMobile ? [] : ['required', $this->phoneRule('whatsappRegion', 'WhatsApp')],
            'confirmed' => ['accepted'],
            'passportScan' => [$editing ? 'nullable' : 'required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            'photo' => [$editing ? 'nullable' : 'required', 'image', 'mimes:jpg,jpeg,png', 'max:5120'],
        ];
    }

    protected function messages(): array
    {
        return [
            'form.passport_expiry_date.after_or_equal' => 'The passport must be valid for at least 6 months after the event ends.',
            'form.passport_number.unique' => 'This passport is already registered for this event.',
            'confirmed.accepted' => 'Please confirm the details are correct.',
            'mobileNumber.required' => 'Enter a valid mobile number for the selected country.',
            'whatsappNumber.required' => 'Enter a valid WhatsApp number for the selected country.',
        ];
    }

    public function save()
    {
        if ($this->applicant) {
            $this->applicant = $this->applicant->fresh();
            Gate::authorize('update', $this->applicant);
        } else {
            abort_unless($this->event->fresh()->isAcceptingApplications(), 403);
        }

        $this->form['passport_number'] = strtoupper(trim((string) ($this->form['passport_number'] ?? '')));
        $this->validate();

        $data = array_intersect_key($this->form, array_flip(self::FIELDS));
        $data['mobile'] = $this->toE164($this->mobileRegion, $this->mobileNumber);
        $data['whatsapp'] = $this->sameAsMobile ? $data['mobile'] : $this->toE164($this->whatsappRegion, $this->whatsappNumber);
        $old = [];

        if ($this->passportScan) {
            $old[] = $this->applicant?->passport_scan_path;
            $data['passport_scan_path'] = $this->store($this->passportScan);
        }
        if ($this->photo) {
            $old[] = $this->applicant?->photo_path;
            $data['photo_path'] = $this->store($this->photo);
        }
        if ($this->ocrRaw) {
            $data['ocr_raw'] = $this->ocrRaw;
        }

        if ($this->applicant) {
            $this->applicant->update($data);
            if ($this->applicant->status === ApplicantStatus::NeedsCorrection) {
                $this->applicant->transitionTo(ApplicantStatus::Submitted, auth()->user(), 'Corrected by applicant');
            }
        } else {
            auth()->user()->applicants()->create($data + ['event_id' => $this->event->id]);
        }

        Storage::disk(config('visa.disk'))->delete(array_filter($old));

        return $this->redirectRoute('dashboard');
    }

    private function store(TemporaryUploadedFile $file): string
    {
        $isPdf = $file->getMimeType() === 'application/pdf';
        $path = 'applicants/'.Str::uuid().($isPdf ? '.pdf' : '.jpg');

        Storage::disk(config('visa.disk'))->put($path, $isPdf ? $file->get() : ImageSanitizer::toJpeg($file->get()));

        return $path;
    }

    private function phoneCodes(): array
    {
        $util = PhoneNumberUtil::getInstance();
        $regions = array_filter($util->getSupportedRegions(), fn ($r) => strlen($r) === 2);
        sort($regions);

        return array_map(fn ($r) => ['region' => $r, 'label' => $r.' +'.$util->getCountryCodeForRegion($r)], $regions);
    }

    public function render()
    {
        return view('livewire.applicant-form', [
            'countries' => (new ISO3166)->all(),
            'phoneCodes' => $this->phoneCodes(),
        ]);
    }
}
