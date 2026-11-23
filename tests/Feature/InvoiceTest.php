<?php

use App\Enums\PaymentStatus;
use App\Jobs\GenerateInvoice;
use App\Models\Applicant;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Notifications\PaymentConfirmed;
use App\Services\InvoiceService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake(config('visa.disk'));
    $this->travelTo('2026-12-01 10:00:00');
});

function paidPayment(): Payment
{
    $payment = Payment::factory()->create(['status' => PaymentStatus::Paid, 'amount' => 60, 'paid_at' => now()]);
    $payment->applicants()->attach(Applicant::factory()->count(2)->create(['event_id' => $payment->event_id]));

    return $payment;
}

it('numbers invoices sequentially per year', function () {
    $service = app(InvoiceService::class);

    expect($service->issue(paidPayment())->number)->toBe('BOC-2026-00001')
        ->and($service->issue(paidPayment())->number)->toBe('BOC-2026-00002');
});

it('issues one invoice per payment and stores the PDF', function () {
    $payment = paidPayment();
    $service = app(InvoiceService::class);

    $first = $service->issue($payment);
    $again = $service->issue($payment);

    expect($again->id)->toBe($first->id)
        ->and($again->wasRecentlyCreated)->toBeFalse()
        ->and((string) $first->total)->toBe('60.000');
    Storage::disk(config('visa.disk'))->assertExists($first->pdf_path);
});

it('issues a credit note in its own series referencing the invoice', function () {
    $service = app(InvoiceService::class);
    $invoice = $service->issue(paidPayment());

    $credit = $service->creditNote($invoice);

    expect($credit->number)->toBe('BOC-CN-2026-00001')
        ->and($credit->type)->toBe('credit_note')
        ->and($credit->references_invoice_id)->toBe($invoice->id);
});

it('emails the invoice once when the job runs twice', function () {
    Notification::fake();
    $payment = paidPayment();

    GenerateInvoice::dispatchSync($payment);
    GenerateInvoice::dispatchSync($payment);

    Notification::assertSentToTimes($payment->user, PaymentConfirmed::class, 1);
});

it('lets the payer and finance download, nobody else', function () {
    $invoice = app(InvoiceService::class)->issue($payment = paidPayment());

    $this->actingAs($payment->user)->get(route('invoices.download', $invoice))->assertOk();
    $this->actingAs(User::factory()->finance()->create())->get(route('invoices.download', $invoice))->assertOk();
    $this->actingAs(User::factory()->create())->get(route('invoices.download', $invoice))->assertForbidden();
});

it('rejects a second invoice row of the same type for a payment', function () {
    $payment = paidPayment();
    app(InvoiceService::class)->issue($payment);

    Invoice::create([
        'payment_id' => $payment->id, 'number' => 'DUP-1', 'type' => 'invoice',
        'subtotal' => 1, 'vat_amount' => 0, 'total' => 1, 'issued_at' => now(),
    ]);
})->throws(QueryException::class);

it('emails on retry when an earlier run issued but never emailed', function () {
    Notification::fake();
    $payment = paidPayment();
    app(InvoiceService::class)->issue($payment); // simulates run that died before notify

    GenerateInvoice::dispatchSync($payment);

    Notification::assertSentToTimes($payment->user, PaymentConfirmed::class, 1);
});
