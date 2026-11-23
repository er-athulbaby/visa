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
        'mobile' => '+46700000000',
        'whatsapp' => '+46700000000',
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
        ->assertSet('form.passport_number', 'L898902C3')
        ->assertSet('form.full_name', 'ANNA MARIA ERIKSSON')
        ->assertSet('ocrMessage', 'Passport read. Please check every field.');
});

it('tells the user to type details when OCR fails', function () {
    $this->mock(PassportOcr::class)->shouldReceive('read')->andReturnNull();

    Livewire::actingAs($this->user)
        ->test(ApplicantForm::class, ['event' => $this->event])
        ->set('passportScan', UploadedFile::fake()->image('passport.jpg'))
        ->assertSet('ocrMessage', "Couldn't read the passport automatically. Please fill in the details.");
});

it('saves a draft applicant with sanitized files', function () {
    $this->mock(PassportOcr::class)->shouldReceive('read')->andReturnNull();

    Livewire::actingAs($this->user)
        ->test(ApplicantForm::class, ['event' => $this->event])
        ->set('form', validForm())
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
        ->call('save')
        ->assertHasErrors('form.passport_expiry_date');
});

it('rejects a duplicate passport for the same event', function () {
    Applicant::factory()->for($this->event)->create(['passport_number' => 'L898902C3']);

    Livewire::actingAs($this->user)
        ->test(ApplicantForm::class, ['event' => $this->event])
        ->set('form', validForm())
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

    $component->set('form', validForm())->call('save')->assertForbidden();
});

it('skips OCR for a PDF passport', function () {
    $this->mock(PassportOcr::class)->shouldNotReceive('read');

    Livewire::actingAs($this->user)
        ->test(ApplicantForm::class, ['event' => $this->event])
        ->set('passportScan', UploadedFile::fake()->create('passport.pdf', 100, 'application/pdf'))
        ->assertSet('ocrMessage', 'PDF uploaded. Please fill in the details.');
});
