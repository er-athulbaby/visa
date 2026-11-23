<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Payment;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class InvoiceService
{
    public function issue(Payment $payment): Invoice
    {
        return DB::transaction(function () use ($payment) {
            Payment::query()->whereKey($payment->id)->lockForUpdate()->first();
            $existing = $payment->invoices()->where('type', 'invoice')->first();
            if ($existing) {
                return $existing;
            }

            $subtotal = (float) $payment->amount;
            $vat = round($subtotal * (float) $payment->event->vat_rate / 100, 3);

            return $this->create($payment, 'invoice', config('visa.invoice.prefix'), $subtotal, $vat);
        });
    }

    public function creditNote(Invoice $original): Invoice
    {
        return DB::transaction(function () use ($original) {
            $payment = $original->payment;
            Payment::query()->whereKey($payment->id)->lockForUpdate()->first();

            return $payment->invoices()->where('type', 'credit_note')->first()
                ?? $this->create(
                    $payment, 'credit_note', config('visa.invoice.prefix').'-CN',
                    (float) $original->subtotal, (float) $original->vat_amount, $original,
                );
        });
    }

    private function create(Payment $payment, string $type, string $prefix, float $subtotal, float $vat, ?Invoice $references = null): Invoice
    {
        $series = $prefix.'-'.now()->year;
        DB::table('invoice_sequences')->insertOrIgnore(['series' => $series, 'last_number' => 0]);
        $next = DB::table('invoice_sequences')->where('series', $series)->lockForUpdate()->value('last_number') + 1;
        DB::table('invoice_sequences')->where('series', $series)->update(['last_number' => $next]);

        $invoice = $payment->invoices()->create([
            'number' => sprintf('%s-%05d', $series, $next),
            'type' => $type,
            'references_invoice_id' => $references?->id,
            'subtotal' => $subtotal,
            'vat_amount' => $vat,
            'total' => $subtotal + $vat,
            'issued_at' => now(),
        ]);

        $path = "invoices/{$invoice->number}.pdf";
        $invoice->load('payment.user', 'payment.event', 'payment.applicants', 'references');
        Storage::disk(config('visa.disk'))->put($path, Pdf::loadView('invoices.pdf', ['invoice' => $invoice])->output());
        $invoice->update(['pdf_path' => $path]);

        return $invoice;
    }
}
