<?php

namespace App\Filament\Widgets;

use App\Enums\ApplicantStatus;
use App\Enums\Role;
use App\Models\Applicant;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ApplicationStats extends StatsOverviewWidget
{
    public static function canView(): bool
    {
        return auth()->user()?->hasRole(Role::Admin, Role::Processor) ?? false;
    }

    protected function getStats(): array
    {
        $count = fn (ApplicantStatus $s) => Applicant::where('status', $s->value)->count();

        return [
            Stat::make('Waiting', $count(ApplicantStatus::Submitted))->description('Submitted, unclaimed'),
            Stat::make('Claimed by me', Applicant::where('status', ApplicantStatus::InReview->value)
                ->where('assigned_processor_id', auth()->id())->count()),
            Stat::make('Applied', $count(ApplicantStatus::Applied)),
            Stat::make('Visas issued', $count(ApplicantStatus::VisaIssued)),
        ];
    }
}
