<?php

use App\Enums\ApplicantStatus;
use App\Livewire\ApplicantForm;
use App\Models\Applicant;
use App\Models\Event;
use App\Models\User;
use App\Services\PassportOcr;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake(config('visa.disk'));
    $this->user = User::factory()->create();
    $this->event = Event::factory()->create(['end_date' => today()->addDays(35)]);
});

function validContact(): array
{
    return [
        'mobileRegion' => 'BH', 'mobileNumber' => '36001234',
        'whatsappRegion' => 'BH', 'whatsappNumber' => '36001234',
        'confirmed' => true,
    ];
}

function validForm(): array
{
    return [
        'full_name' => 'Anna Maria Eriksson',
        'email' => 'anna@example.com',
        'nationality' => 'SWE',
        'date_of_birth' => '1974-08-12',
        'passport_number' => 'L898902C3',
        'issuing_country' => 'SWE',
        'passport_issue_date' => today()->subYear()->toDateString(),
        'passport_expiry_date' => today()->addYears(5)->toDateString(),
    ];
}

it('prefills fields from the passport MRZ', function () {
    $this->mock(PassportOcr::class)->shouldReceive('read')->once()->andReturn([
        'full_name' => 'ANNA MARIA ERIKSSON', 'passport_number' => 'L898902C3', 'nationality' => 'SWE',
        'issuing_country' => 'SWE', 'date_of_birth' => '1974-08-12', 'passport_expiry_date' => '2031-04-15',
    ]);

    Livewire::actingAs($this->user)
        ->test(ApplicantForm::class, ['event' => $this->event])
        ->set('passportScan', UploadedFile::fake()->image('passport.jpg'))
        ->call('readPassport')
        ->assertSet('form.passport_number', 'L898902C3')
        ->assertSet('form.full_name', 'ANNA MARIA ERIKSSON')
        ->assertSet('ocrMessage', 'Passport read. Please check every field.');
});

it('tells the user to type details when OCR fails', function () {
    $this->mock(PassportOcr::class)->shouldReceive('read')->andReturnNull();

    Livewire::actingAs($this->user)
        ->test(ApplicantForm::class, ['event' => $this->event])
        ->set('passportScan', UploadedFile::fake()->image('passport.jpg'))
        ->call('readPassport')
        ->assertSet('ocrMessage', "Couldn't read the passport automatically. Please fill in the details.");
});

it('saves a draft applicant with sanitized files', function () {
    $this->mock(PassportOcr::class)->shouldReceive('read')->andReturnNull();

    Livewire::actingAs($this->user)
        ->test(ApplicantForm::class, ['event' => $this->event])
        ->set('form', validForm())
        ->set(validContact())
        ->set('passportScan', UploadedFile::fake()->image('passport.jpg'))
        ->set('photo', UploadedFile::fake()->image('photo.png'))
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('dashboard'));

    $applicant = Applicant::sole();
    expect($applicant->status)->toBe(ApplicantStatus::Draft)
        ->and($applicant->user_id)->toBe($this->user->id)
        ->and($applicant->photo_path)->toEndWith('.jpg');
    Storage::disk(config('visa.disk'))->assertExists([$applicant->passport_scan_path, $applicant->photo_path]);
});

it('rejects a passport expiring within six months of the event end', function () {
    Livewire::actingAs($this->user)
        ->test(ApplicantForm::class, ['event' => $this->event])
        ->set('form', ['passport_expiry_date' => today()->addDays(60)->toDateString()] + validForm())
        ->set(validContact())
        ->call('save')
        ->assertHasErrors('form.passport_expiry_date');
});

it('rejects a duplicate passport for the same event', function () {
    Applicant::factory()->for($this->event)->create(['passport_number' => 'L898902C3']);

    Livewire::actingAs($this->user)
        ->test(ApplicantForm::class, ['event' => $this->event])
        ->set('form', validForm())
        ->set(validContact())
        ->call('save')
        ->assertHasErrors('form.passport_number');
});

it('refuses new applicants for a closed event', function () {
    $this->event->update(['is_open' => false]);

    $this->actingAs($this->user)
        ->get(route('applicants.create', $this->event))
        ->assertForbidden();
});

it('resubmits a corrected application', function () {
    Notification::fake();
    $applicant = Applicant::factory()->for($this->user)->for($this->event)
        ->status(ApplicantStatus::NeedsCorrection)->create();

    Livewire::actingAs($this->user)
        ->test(ApplicantForm::class, ['applicant' => $applicant])
        ->set('photo', UploadedFile::fake()->image('new.jpg'))
        ->set('confirmed', true)
        ->call('save')
        ->assertHasNoErrors();

    expect($applicant->fresh()->status)->toBe(ApplicantStatus::Submitted);
});

it('does not let users edit someone else’s applicant', function () {
    $applicant = Applicant::factory()->create();

    $this->actingAs($this->user)->get(route('applicants.edit', $applicant))->assertForbidden();
});

it('replaces the photo on edit, deleting the old file', function () {
    $disk = Storage::disk(config('visa.disk'));
    $disk->put('applicants/old.jpg', 'x');
    $applicant = Applicant::factory()->for($this->user)->for($this->event)
        ->status(ApplicantStatus::Draft)->create(['photo_path' => 'applicants/old.jpg']);

    Livewire::actingAs($this->user)
        ->test(ApplicantForm::class, ['applicant' => $applicant])
        ->set('photo', UploadedFile::fake()->image('new.jpg'))
        ->set('confirmed', true)
        ->call('save')
        ->assertHasNoErrors();

    $new = $applicant->fresh()->photo_path;
    expect($new)->not->toBe('applicants/old.jpg');
    $disk->assertMissing('applicants/old.jpg');
    $disk->assertExists($new);
});

it('allows saving an applicant with its own unchanged passport number', function () {
    $applicant = Applicant::factory()->for($this->user)->for($this->event)
        ->status(ApplicantStatus::Draft)->create();

    Livewire::actingAs($this->user)
        ->test(ApplicantForm::class, ['applicant' => $applicant])
        ->set('form.passport_expiry_date', today()->addYears(5)->toDateString())
        ->set('form.passport_issue_date', today()->subYear()->toDateString())
        ->call('save')
        ->assertHasNoErrors('form.passport_number');
});

it('forbids saving after the applicant left an editable status', function () {
    $applicant = Applicant::factory()->for($this->user)->for($this->event)
        ->status(ApplicantStatus::NeedsCorrection)->create();

    $component = Livewire::actingAs($this->user)->test(ApplicantForm::class, ['applicant' => $applicant]);
    $applicant->forceFill(['status' => ApplicantStatus::InReview])->save();

    $component->call('save')->assertForbidden();
});

it('forbids creating after the event closed', function () {
    $component = Livewire::actingAs($this->user)->test(ApplicantForm::class, ['event' => $this->event]);
    $this->event->update(['is_open' => false]);

    $component->set('form', validForm())
        ->set(validContact())->call('save')->assertForbidden();
});

it('reads a PDF passport when asked, like a photo', function () {
    $ocr = $this->mock(PassportOcr::class);
    $ocr->shouldReceive('read')->once()->andReturn(['passport_number' => 'L898902C3', 'full_name' => 'ANNA MARIA ERIKSSON']);

    Livewire::actingAs($this->user)
        ->test(ApplicantForm::class, ['event' => $this->event])
        ->set('passportScan', UploadedFile::fake()->create('passport.pdf', 100, 'application/pdf'))
        ->assertSet('ocrMessage', null)
        ->assertSee('Read details from passport')
        ->call('readPassport')
        ->assertSet('form.passport_number', 'L898902C3')
        ->assertSet('ocrMessage', 'Passport read. Please check every field.');
});

it('does not let the client write ocrRaw', function () {
    Livewire::actingAs($this->user)
        ->test(ApplicantForm::class, ['event' => $this->event])
        ->set('ocrRaw', ['x' => 'y']);
})->throws(\Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException::class);

function newForm()
{
    return Livewire::actingAs(test()->user)->test(ApplicantForm::class, ['event' => test()->event])
        ->set('passportScan', UploadedFile::fake()->image('passport.jpg'))
        ->set('photo', UploadedFile::fake()->image('photo.jpg'));
}

it('rejects a phone number invalid for the selected country', function () {
    newForm()->set('form', validForm())->set(validContact())
        ->set('mobileNumber', '123')
        ->call('save')
        ->assertHasErrors('mobileNumber')
        ->assertSee('Enter a valid mobile number for the selected country.');
});

it('rejects an invalid WhatsApp number', function () {
    newForm()->set('form', validForm())->set(validContact())
        ->set('whatsappNumber', '123')
        ->call('save')
        ->assertHasErrors('whatsappNumber')
        ->assertSee('Enter a valid WhatsApp number for the selected country.');
});

it('stores phone numbers as E.164', function () {
    newForm()->set('form', validForm())->set(validContact())
        ->call('save')->assertHasNoErrors();

    expect(Applicant::sole())->mobile->toBe('+97336001234')->whatsapp->toBe('+97336001234');
});

it('splits a stored E.164 number back into code and number on edit', function () {
    $applicant = Applicant::factory()->for($this->user)->for($this->event)
        ->status(ApplicantStatus::Draft)->create(['mobile' => '+97336001234', 'whatsapp' => '+919800000000']);

    Livewire::actingAs($this->user)
        ->test(ApplicantForm::class, ['applicant' => $applicant])
        ->assertSet('mobileRegion', 'BH')->assertSet('mobileNumber', '36001234')
        ->assertSet('whatsappRegion', 'IN')->assertSet('whatsappNumber', '9800000000');
});

it('requires the confirmation checkbox', function () {
    newForm()->set('form', validForm())->set(validContact())
        ->set('confirmed', false)
        ->call('save')
        ->assertHasErrors('confirmed')
        ->assertSee('Please confirm the details are correct.');
});

it('does not run OCR on upload, only when readPassport is called', function () {
    $ocr = $this->mock(PassportOcr::class);
    $ocr->shouldNotReceive('read');

    $component = newForm()->set('passportScan', UploadedFile::fake()->image('passport.jpg'));

    $ocr->shouldReceive('read')->once()->andReturnNull();
    $component->call('readPassport');
});

it('copies mobile into WhatsApp when "same as mobile" is ticked', function () {
    newForm()->set('form', validForm())->set(validContact())
        ->set('whatsappRegion', null)->set('whatsappNumber', null)
        ->set('sameAsMobile', true)
        ->call('save')->assertHasNoErrors();

    expect(Applicant::sole()->whatsapp)->toBe('+97336001234');
});
