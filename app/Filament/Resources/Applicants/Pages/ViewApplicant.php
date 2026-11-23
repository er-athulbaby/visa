<?php

namespace App\Filament\Resources\Applicants\Pages;

use App\Enums\ApplicantStatus;
use App\Enums\Role;
use App\Filament\Resources\Applicants\ApplicantResource;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ViewRecord;

class ViewApplicant extends ViewRecord
{
    protected static string $resource = ApplicantResource::class;

    private function is(ApplicantStatus ...$statuses): bool
    {
        return in_array($this->record->status, $statuses, true);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('claim')
                ->label('Claim')
                ->visible(fn () => $this->is(ApplicantStatus::Submitted))
                ->action(fn () => $this->record->transitionTo(ApplicantStatus::InReview, auth()->user(),
                    attributes: ['assigned_processor_id' => auth()->id()])),

            Action::make('requestCorrection')
                ->label('Request correction')->color('warning')
                ->visible(fn () => $this->is(ApplicantStatus::InReview))
                ->schema([Textarea::make('note')->label('What must the applicant fix?')->required()->maxLength(1000)])
                ->action(fn (array $data) => $this->record->transitionTo(ApplicantStatus::NeedsCorrection, auth()->user(),
                    $data['note'], ['correction_note' => $data['note']])),

            Action::make('markApplied')
                ->label('Mark applied')
                ->visible(fn () => $this->is(ApplicantStatus::InReview))
                ->schema([TextInput::make('gov_reference_no')->label('Government reference number')->required()->maxLength(100)])
                ->action(fn (array $data) => $this->record->transitionTo(ApplicantStatus::Applied, auth()->user(),
                    attributes: ['gov_reference_no' => $data['gov_reference_no']])),

            Action::make('issueVisa')
                ->label('Issue visa')->color('success')
                ->visible(fn () => $this->is(ApplicantStatus::Applied))
                ->schema([FileUpload::make('visa_pdf')->label('Visa PDF')->required()
                    ->disk(config('visa.disk'))->directory('visas')->visibility('private')
                    ->acceptedFileTypes(['application/pdf'])->maxSize(5120)])
                ->action(fn (array $data) => $this->record->transitionTo(ApplicantStatus::VisaIssued, auth()->user(),
                    attributes: ['visa_pdf_path' => $data['visa_pdf']])),

            Action::make('reject')
                ->color('danger')->requiresConfirmation()
                ->visible(fn () => $this->is(ApplicantStatus::InReview, ApplicantStatus::Applied))
                ->schema([Textarea::make('reason')->required()->maxLength(1000)])
                ->action(fn (array $data) => $this->record->transitionTo(ApplicantStatus::Rejected, auth()->user(),
                    $data['reason'], ['rejection_reason' => $data['reason']])),

            Action::make('override')
                ->label('Override status')->color('gray')
                ->visible(fn () => auth()->user()->hasRole(Role::Admin))
                ->schema([
                    Select::make('status')->options(ApplicantStatus::class)->required(),
                    Textarea::make('note')->label('Reason for override')->required(),
                ])
                ->action(function (array $data) {
                    $status = $data['status'] instanceof ApplicantStatus ? $data['status'] : ApplicantStatus::from($data['status']);
                    $this->record->transitionTo($status, auth()->user(), $data['note'], override: true);
                }),
        ];
    }
}
