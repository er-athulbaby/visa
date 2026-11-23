<?php

use App\Enums\ApplicantStatus;

it('allows only the agreed transitions', function () {
    expect(ApplicantStatus::Draft->canTransitionTo(ApplicantStatus::PendingPayment))->toBeTrue()
        ->and(ApplicantStatus::Draft->canTransitionTo(ApplicantStatus::VisaIssued))->toBeFalse()
        ->and(ApplicantStatus::InReview->canTransitionTo(ApplicantStatus::NeedsCorrection))->toBeTrue()
        ->and(ApplicantStatus::NeedsCorrection->canTransitionTo(ApplicantStatus::Submitted))->toBeTrue()
        ->and(ApplicantStatus::Applied->canTransitionTo(ApplicantStatus::Rejected))->toBeTrue()
        ->and(ApplicantStatus::VisaIssued->allowedNext())->toBe([])
        ->and(ApplicantStatus::Rejected->allowedNext())->toBe([]);
});

it('labels statuses for humans', function () {
    expect(ApplicantStatus::NeedsCorrection->label())->toBe('Needs Correction');
});
