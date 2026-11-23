<?php

namespace App\Filament\Exports;

use App\Models\Payment;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;

class PaymentExporter extends Exporter
{
    protected static ?string $model = Payment::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('id'),
            ExportColumn::make('created_at')->label('Date'),
            ExportColumn::make('user.name')->label('Payer'),
            ExportColumn::make('user.email')->label('Payer email'),
            ExportColumn::make('event.name')->label('Event'),
            ExportColumn::make('applicants_count')->counts('applicants')->label('Applicants'),
            ExportColumn::make('amount'),
            ExportColumn::make('currency'),
            ExportColumn::make('status')->formatStateUsing(fn ($state) => $state->label()),
            ExportColumn::make('payment_method'),
            ExportColumn::make('tap_charge_id'),
            ExportColumn::make('paid_at'),
            ExportColumn::make('refunded_at'),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        return 'Payment export finished: '.number_format($export->successful_rows).' rows.';
    }
}
