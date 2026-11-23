<?php

namespace App\Policies;

use App\Enums\ApplicantStatus;
use App\Enums\Role;
use App\Models\Applicant;
use App\Models\User;

class ApplicantPolicy
{
    private function isCaseworker(User $user): bool
    {
        return $user->hasRole(Role::Admin, Role::Processor);
    }

    public function viewAny(User $user): bool
    {
        return $this->isCaseworker($user);
    }

    public function view(User $user, Applicant $applicant): bool
    {
        return $applicant->user_id === $user->id || $this->isCaseworker($user);
    }

    public function viewDocuments(User $user, Applicant $applicant): bool
    {
        return $this->view($user, $applicant);
    }

    public function downloadVisa(User $user, Applicant $applicant): bool
    {
        return $this->isCaseworker($user)
            || ($applicant->user_id === $user->id && $applicant->status === ApplicantStatus::VisaIssued);
    }

    public function create(User $user): bool
    {
        return false; // staff never create applicants in Filament; the portal checks ownership itself
    }

    public function update(User $user, Applicant $applicant): bool
    {
        return $applicant->user_id === $user->id && $applicant->status->isEditableByApplicant();
    }

    public function delete(User $user, Applicant $applicant): bool
    {
        return $applicant->user_id === $user->id && $applicant->status === ApplicantStatus::Draft;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
