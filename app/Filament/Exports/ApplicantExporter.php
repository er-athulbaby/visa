<?php

namespace App\Filament\Exports;

use App\Models\Applicant;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;

class ApplicantExporter extends Exporter
{
    protected static ?string $model = Applicant::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('id'),
            ExportColumn::make('event.name')->label('Event'),
            ExportColumn::make('full_name'),
            ExportColumn::make('email'),
            ExportColumn::make('nationality'),
            ExportColumn::make('date_of_birth'),
            ExportColumn::make('passport_number'),
            ExportColumn::make('issuing_country'),
            ExportColumn::make('passport_issue_date'),
            ExportColumn::make('passport_expiry_date'),
            ExportColumn::make('mobile'),
            ExportColumn::make('whatsapp'),
            ExportColumn::make('status')->formatStateUsing(fn ($state) => $state->label()),
            ExportColumn::make('gov_reference_no'),
            ExportColumn::make('submitted_at'),
            ExportColumn::make('issued_at'),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        return 'Applicant export finished: '.number_format($export->successful_rows).' rows.';
    }
}
