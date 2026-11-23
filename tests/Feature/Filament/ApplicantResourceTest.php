<?php

use App\Enums\ApplicantStatus;
use App\Filament\Resources\Applicants\Pages\ListApplicants;
use App\Filament\Resources\Applicants\Pages\ViewApplicant;
use App\Models\Applicant;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Notification::fake();
    $this->processor = User::factory()->processor()->create();
    $this->actingAs($this->processor);
});

it('shows paid applications and hides drafts', function () {
    $submitted = Applicant::factory()->status(ApplicantStatus::Submitted)->create();
    $draft = Applicant::factory()->create();

    Livewire::test(ListApplicants::class)
        ->assertCanSeeTableRecords([$submitted])
        ->assertCanNotSeeTableRecords([$draft]);
});

it('walks an application through claim, correction, applied and rejection', function () {
    $a = Applicant::factory()->status(ApplicantStatus::Submitted)->create();
    $page = fn () => Livewire::test(ViewApplicant::class, ['record' => $a->getRouteKey()]);

    $page()->callAction('claim')->assertHasNoActionErrors();
    expect($a->fresh())->status->toBe(ApplicantStatus::InReview)->assigned_processor_id->toBe($this->processor->id);

    $page()->callAction('requestCorrection', data: ['note' => 'Photo background must be white'])->assertHasNoActionErrors();
    expect($a->fresh())->status->toBe(ApplicantStatus::NeedsCorrection)->correction_note->toBe('Photo background must be white');

    $a->fresh()->transitionTo(ApplicantStatus::Submitted);
    $page()->callAction('claim');
    $page()->callAction('markApplied', data: ['gov_reference_no' => 'EV-123'])->assertHasNoActionErrors();
    expect($a->fresh())->status->toBe(ApplicantStatus::Applied)->gov_reference_no->toBe('EV-123');

    $page()->callAction('reject', data: ['reason' => 'Refused by immigration'])->assertHasNoActionErrors();
    expect($a->fresh())->status->toBe(ApplicantStatus::Rejected);
});

it('lets only admins override a status', function () {
    $a = Applicant::factory()->status(ApplicantStatus::Rejected)->create();

    Livewire::test(ViewApplicant::class, ['record' => $a->getRouteKey()])->assertActionHidden('override');

    $this->actingAs(User::factory()->admin()->create());
    Livewire::test(ViewApplicant::class, ['record' => $a->getRouteKey()])
        ->callAction('override', data: ['status' => ApplicantStatus::InReview->value, 'note' => 'Rejected by mistake'])
        ->assertHasNoActionErrors();

    expect($a->fresh()->status)->toBe(ApplicantStatus::InReview);
});

it('keeps finance out of applicant records', function () {
    expect(User::factory()->finance()->create()->can('viewAny', Applicant::class))->toBeFalse();
});

it('forbids finance from the applicant pages', function () {
    $a = Applicant::factory()->status(ApplicantStatus::Submitted)->create();
    $this->actingAs(User::factory()->finance()->create());

    Livewire::test(ListApplicants::class)->assertForbidden();
    Livewire::test(ViewApplicant::class, ['record' => $a->getRouteKey()])->assertForbidden();
});

it('notifies instead of failing when a claim loses a race', function () {
    $a = Applicant::factory()->status(ApplicantStatus::Submitted)->create();
    $other = User::factory()->processor()->create();

    // Another processor claims it in the database; this page still holds the stale "Submitted" copy.
    $a->transitionTo(ApplicantStatus::InReview, $other, attributes: ['assigned_processor_id' => $other->id]);
    // Only full-row loads (not the lock query's single-column read) are made stale.
    Applicant::retrieved(fn (Applicant $loaded) => $loaded->exists && $loaded->getAttribute("email") !== null ? $loaded->setRawAttributes(
        ['status' => ApplicantStatus::Submitted->value] + $loaded->getAttributes(), true
    ) : null);

    Livewire::test(ViewApplicant::class, ['record' => $a->getRouteKey()])
        ->callAction("claim")
        ->assertNotified('This application was already changed by someone else.');

    Applicant::flushEventListeners();
    expect($a->fresh())->status->toBe(ApplicantStatus::InReview)->assigned_processor_id->toBe($other->id);
});

it('issues a visa with an uploaded pdf', function () {
    Storage::fake(config('visa.disk'));
    $a = Applicant::factory()->status(ApplicantStatus::Applied)->create();

    Livewire::test(ViewApplicant::class, ['record' => $a->getRouteKey()])
        ->callAction('issueVisa', data: ['visa_pdf' => UploadedFile::fake()->create('visa.pdf', 20, 'application/pdf')])
        ->assertHasNoActionErrors();

    $a->refresh();
    expect($a->status)->toBe(ApplicantStatus::VisaIssued)->and($a->visa_pdf_path)->not->toBeNull();
    Storage::disk(config('visa.disk'))->assertExists($a->visa_pdf_path);
});

it('zips documents for selected applicants and warns when there are none', function () {
    Storage::fake(config('visa.disk'));
    $disk = Storage::disk(config('visa.disk'));
    $with = Applicant::factory()->count(2)->status(ApplicantStatus::Submitted)->create();
    $with->each(fn ($x) => $disk->put($x->passport_scan_path, 'p') && $disk->put($x->photo_path, 'i'));

    Livewire::test(ListApplicants::class)
        ->callTableBulkAction('downloadDocuments', $with)
        ->assertFileDownloaded();

    $disk->deleteDirectory('applicants');
    Livewire::test(ListApplicants::class)
        ->callTableBulkAction('downloadDocuments', $with)
        ->assertNotified('No documents to download');
});
