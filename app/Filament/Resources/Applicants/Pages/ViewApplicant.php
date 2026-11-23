<?php

namespace App\Filament\Resources\Applicants\Pages;

use App\Enums\ApplicantStatus;
use App\Enums\Role;
use App\Exceptions\InvalidStatusTransition;
use App\Filament\Resources\Applicants\ApplicantResource;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Support\Facades\Storage;
use IlluminateSupportFacadesDB;

class ViewApplicant extends ViewRecord
{
    protected static string $resource = ApplicantResource::class;

    private function is(ApplicantStatus ...$statuses): bool
    {
        return in_array($this->record->status, $statuses, true);
    }

    /** Runs a transition; a lost race (stale record) becomes a notification instead of a 500. */
    private function applyTransition(ApplicantStatus $to, ?string $note = null, array $attributes = [], bool $override = false): void
    {
        try {
            $this->record->transitionTo($to, auth()->user(), $note, $attributes, $override);
        } catch (InvalidStatusTransition) {
            $this->record->refresh();
            Notification::make()->title('This application was already changed by someone else.')->danger()->send();

            throw new Halt;
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('claim')
                ->label('Claim')
                ->visible(fn () => $this->is(ApplicantStatus::Submitted))
                ->action(fn () => $this->applyTransition(ApplicantStatus::InReview,
                    attributes: ['assigned_processor_id' => auth()->id()])),

            Action::make('requestCorrection')
                ->label('Request correction')->color('warning')
                ->visible(fn () => $this->is(ApplicantStatus::InReview))
                ->schema([Textarea::make('note')->label('What must the applicant fix?')->required()->maxLength(1000)])
                ->action(fn (array $data) => $this->applyTransition(ApplicantStatus::NeedsCorrection,
                    $data['note'], ['correction_note' => $data['note']])),

            Action::make('markApplied')
                ->label('Mark applied')
                ->visible(fn () => $this->is(ApplicantStatus::InReview))
                ->schema([TextInput::make('gov_reference_no')->label('Government reference number')->required()->maxLength(100)])
                ->action(fn (array $data) => $this->applyTransition(ApplicantStatus::Applied,
                    attributes: ['gov_reference_no' => $data['gov_reference_no']])),

            Action::make('issueVisa')
                ->label('Issue visa')->color('success')
                ->visible(fn () => $this->is(ApplicantStatus::Applied))
                ->schema([FileUpload::make('visa_pdf')->label('Visa PDF')->required()
                    ->disk(config('visa.disk'))->directory('visas')->visibility('private')
                    ->acceptedFileTypes(['application/pdf'])->maxSize(5120)])
                ->action(function (array $data) {
                    try {
                        $this->applyTransition(ApplicantStatus::VisaIssued, attributes: ['visa_pdf_path' => $data['visa_pdf']]);
                    } catch (Halt $e) {
                        Storage::disk(config('visa.disk'))->delete($data['visa_pdf']);

                        throw $e;
                    }
                }),

            Action::make('reject')
                ->color('danger')->requiresConfirmation()
                ->visible(fn () => $this->is(ApplicantStatus::InReview, ApplicantStatus::Applied))
                ->schema([Textarea::make('reason')->required()->maxLength(1000)])
                ->action(fn (array $data) => $this->applyTransition(ApplicantStatus::Rejected,
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
                    $this->applyTransition($status, $data['note'], override: true);
                }),
        ];
    }
}
