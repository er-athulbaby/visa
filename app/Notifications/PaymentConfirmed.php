<?php

namespace App\Notifications;

use App\Models\Invoice;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Storage;

class PaymentConfirmed extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Invoice $invoice) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $payment = $this->invoice->payment;

        return (new MailMessage)
            ->subject("Payment received — {$payment->event->name}")
            ->line("We received your payment of {$payment->currency} ".number_format((float) $payment->amount, 3)
                ." for {$payment->applicants()->count()} visa application(s).")
            ->line('Our team will now process the applications. You will be emailed at each step.')
            ->line("Invoice {$this->invoice->number} is attached.")
            ->action('Open your dashboard', route('dashboard'))
            ->attachData(
                Storage::disk(config('visa.disk'))->get($this->invoice->pdf_path),
                "{$this->invoice->number}.pdf",
                ['mime' => 'application/pdf'],
            );
    }
}
