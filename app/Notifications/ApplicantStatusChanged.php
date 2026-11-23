<?php

namespace App\Notifications;

use App\Enums\ApplicantStatus;
use App\Models\Applicant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Storage;

class ApplicantStatusChanged extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Applicant $applicant) {}

    public function via(object $notifiable): array
    {
        return ['mail']; // WhatsApp channel added here later.
    }

    public function toMail(object $notifiable): MailMessage
    {
        $a = $this->applicant;
        $mail = (new MailMessage)
            ->subject("Visa application update: {$a->full_name}")
            ->line("The visa application for {$a->full_name} ({$a->event->name}) is now: {$a->status->label()}.");

        match ($a->status) {
            ApplicantStatus::NeedsCorrection => $mail->line('What needs fixing: '.$a->correction_note),
            ApplicantStatus::Applied => $mail->line('We have submitted the application to the Bahrain eVisa system.'
                .($a->gov_reference_no ? " Reference: {$a->gov_reference_no}." : '')),
            ApplicantStatus::VisaIssued => filled($a->visa_pdf_path) && Storage::disk(config('visa.disk'))->exists($a->visa_pdf_path)
                ? $mail->line('The visa is attached and can also be downloaded from your dashboard.')
                    ->attachData(
                        Storage::disk(config('visa.disk'))->get($a->visa_pdf_path),
                        "visa-{$a->passport_number}.pdf",
                        ['mime' => 'application/pdf'],
                    )
                : $mail->line('Your visa has been issued; it can be downloaded from your dashboard.'),
            ApplicantStatus::Rejected => $mail->line('Reason: '.$a->rejection_reason),
            default => null,
        };

        return $mail->action('Open your dashboard', route('dashboard'));
    }
}
