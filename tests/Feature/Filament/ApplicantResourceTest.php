<?php

use App\Enums\ApplicantStatus;
use App\Filament\Resources\Applicants\Pages\ListApplicants;
use App\Filament\Resources\Applicants\Pages\ViewApplicant;
use App\Models\Applicant;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
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
