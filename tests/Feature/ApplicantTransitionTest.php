<?php

use App\Enums\ApplicantStatus;
use App\Exceptions\InvalidStatusTransition;
use App\Models\Applicant;
use App\Models\User;
use App\Notifications\ApplicantStatusChanged;
use Illuminate\Support\Facades\Notification;

beforeEach(fn () => Notification::fake());

it('moves through an allowed transition and logs it', function () {
    $processor = User::factory()->processor()->create();
    $applicant = Applicant::factory()->status(ApplicantStatus::Submitted)->create();

    $applicant->transitionTo(ApplicantStatus::InReview, $processor, attributes: ['assigned_processor_id' => $processor->id]);

    $fresh = $applicant->fresh();
    expect($fresh->status)->toBe(ApplicantStatus::InReview)
        ->and($fresh->assigned_processor_id)->toBe($processor->id);

    $log = $fresh->statusLogs()->first();
    expect($log->from_status)->toBe(ApplicantStatus::Submitted)
        ->and($log->to_status)->toBe(ApplicantStatus::InReview)
        ->and($log->user_id)->toBe($processor->id);
});

it('stamps milestone timestamps', function () {
    $applicant = Applicant::factory()->status(ApplicantStatus::PendingPayment)->create();

    $applicant->transitionTo(ApplicantStatus::Submitted);

    expect($applicant->fresh()->submitted_at)->not->toBeNull();
});

it('refuses a disallowed transition', function () {
    $applicant = Applicant::factory()->create();

    expect(fn () => $applicant->transitionTo(ApplicantStatus::VisaIssued))
        ->toThrow(InvalidStatusTransition::class);
    expect($applicant->fresh()->status)->toBe(ApplicantStatus::Draft);
});

it('refuses a transition from a stale in-memory status', function () {
    $applicant = Applicant::factory()->status(ApplicantStatus::Submitted)->create();
    $stale = Applicant::find($applicant->id);
    $applicant->transitionTo(ApplicantStatus::InReview);

    expect(fn () => $stale->transitionTo(ApplicantStatus::InReview))
        ->toThrow(InvalidStatusTransition::class);
});

it('allows an admin override only with a note', function () {
    $admin = User::factory()->admin()->create();
    $applicant = Applicant::factory()->status(ApplicantStatus::Rejected)->create();

    expect(fn () => $applicant->transitionTo(ApplicantStatus::InReview, $admin, override: true))
        ->toThrow(InvalidArgumentException::class);

    $applicant->transitionTo(ApplicantStatus::InReview, $admin, 'Rejected by mistake', override: true);
    expect($applicant->fresh()->status)->toBe(ApplicantStatus::InReview);
});

it('notifies the submitter and the applicant on a correction request', function () {
    $applicant = Applicant::factory()->status(ApplicantStatus::InReview)->create();

    $applicant->transitionTo(ApplicantStatus::NeedsCorrection, null, 'Photo background must be white', ['correction_note' => 'Photo background must be white']);

    Notification::assertSentTo($applicant->user, ApplicantStatusChanged::class);
    Notification::assertSentOnDemand(ApplicantStatusChanged::class,
        fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === $applicant->email);
});

it('does not notify when moving a draft into checkout', function () {
    $applicant = Applicant::factory()->create();

    $applicant->transitionTo(ApplicantStatus::PendingPayment);

    Notification::assertNothingSent();
});
