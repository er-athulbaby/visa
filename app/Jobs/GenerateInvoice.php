<?php

namespace App\Jobs;

use App\Models\Invoice;
use App\Models\Payment;
use App\Notifications\PaymentConfirmed;
use App\Services\InvoiceService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GenerateInvoice implements ShouldQueue
{
    use Queueable;

    public function __construct(public Payment $payment) {}

    public function handle(InvoiceService $invoices): void
    {
        $invoice = $invoices->issue($this->payment);

        $claimed = Invoice::whereKey($invoice->id)->whereNull('emailed_at')->update(['emailed_at' => now()]);

        if ($claimed === 1) {
            $this->payment->user->notify(new PaymentConfirmed($invoice));
        }
    }
}
