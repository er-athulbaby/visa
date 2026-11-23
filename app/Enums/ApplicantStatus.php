<?php

namespace App\Enums;

enum ApplicantStatus: string
{
    case Draft = 'draft';
    case PendingPayment = 'pending_payment';
    case Submitted = 'submitted';
    case InReview = 'in_review';
    case NeedsCorrection = 'needs_correction';
    case Applied = 'applied';
    case VisaIssued = 'visa_issued';
    case Rejected = 'rejected';

    /** @return list<self> */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Draft => [self::PendingPayment],
            self::PendingPayment => [self::Draft, self::Submitted],
            self::Submitted => [self::InReview],
            self::InReview => [self::NeedsCorrection, self::Applied, self::Rejected],
            self::NeedsCorrection => [self::Submitted],
            self::Applied => [self::VisaIssued, self::Rejected],
            self::VisaIssued, self::Rejected => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedNext(), true);
    }

    public function isEditableByApplicant(): bool
    {
        return $this === self::Draft || $this === self::NeedsCorrection;
    }

    public function label(): string
    {
        return ucwords(str_replace('_', ' ', $this->value));
    }
}
