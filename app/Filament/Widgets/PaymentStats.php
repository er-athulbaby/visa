<?php

namespace App\Filament\Widgets;

use App\Enums\PaymentStatus;
use App\Enums\Role;
use App\Models\Payment;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class PaymentStats extends StatsOverviewWidget
{
    public static function canView(): bool
    {
        return auth()->user()?->hasRole(Role::Admin, Role::Finance) ?? false;
    }

    protected function getStats(): array
    {
        $count = fn (PaymentStatus $s) => Payment::where('status', $s->value)->count();

        return [
            Stat::make('Revenue (paid)', 'BHD '.number_format((float) Payment::where('status', PaymentStatus::Paid->value)->sum('amount'), 3)),
            Stat::make('Paid', $count(PaymentStatus::Paid)),
            Stat::make('Failed', $count(PaymentStatus::Failed)),
            Stat::make('Refunded', $count(PaymentStatus::Refunded)),
        ];
    }
}
