<?php

namespace App\Services;

use App\Enums\ApplicantStatus;
use App\Enums\PaymentStatus;
use App\Jobs\GenerateInvoice;
use App\Models\Applicant;
use App\Models\Event;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PaymentService
{
    private const FAILED_STATUSES = ['ABANDONED', 'CANCELLED', 'FAILED', 'DECLINED', 'RESTRICTED', 'VOID', 'TIMEDOUT'];

    public function __construct(private TapClient $tap, private InvoiceService $invoices) {}

    /** @param Collection<int, Applicant> $applicants  @return string Tap hosted checkout URL */
    public function start(User $user, Event $event, Collection $applicants): string
    {
        $valid = $applicants->every(fn (Applicant $a) => $a->user_id === $user->id
            && $a->event_id === $event->id && $a->status === ApplicantStatus::Draft);

        if ($applicants->isEmpty() || ! $valid || ! $event->isAcceptingApplications()) {
            throw new \InvalidArgumentException('Select draft applicants from one open event.');
        }

        return DB::transaction(function () use ($user, $event, $applicants) {
            $payment = Payment::create([
                'user_id' => $user->id,
                'event_id' => $event->id,
                'amount' => (float) $event->visa_fee * $applicants->count(),
                'currency' => $event->currency,
                'status' => PaymentStatus::Initiated,
            ]);
            $payment->applicants()->attach($applicants->pluck('id'));
            $applicants->each->transitionTo(ApplicantStatus::PendingPayment, $user);

            $charge = $this->tap->createCharge($payment, $user); // throws → whole transaction rolls back
            $payment->update(['tap_charge_id' => $charge['id']]);

            return $charge['transaction']['url'];
        });
    }

    public function syncFromCharge(Payment $payment, array $charge): void
    {
        $status = strtoupper($charge['status'] ?? '');

        if ($status === 'CAPTURED') {
            $this->markPaid($payment, $charge);
        } elseif (in_array($status, self::FAILED_STATUSES, true)) {
            $this->markFailed($payment, $charge);
        }
    }

    public function markPaid(Payment $payment, array $charge): void
    {
        $changed = DB::transaction(function () use ($payment, $charge) {
            $locked = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            if ($locked->status !== PaymentStatus::Initiated) {
                return false; // already handled — webhook/return/reconcile can all race here
            }

            $locked->update([
                'status' => PaymentStatus::Paid,
                'paid_at' => now(),
                'payment_method' => $charge['source']['payment_method'] ?? null,
                'raw_payload' => $charge,
            ]);
            $locked->applicants->each->transitionTo(ApplicantStatus::Submitted);

            return true;
        });

        if ($changed) {
            GenerateInvoice::dispatch($payment->fresh());
        }
    }

    public function markFailed(Payment $payment, array $charge): void
    {
        DB::transaction(function () use ($payment, $charge) {
            $locked = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            if ($locked->status !== PaymentStatus::Initiated) {
                return;
            }

            $locked->update(['status' => PaymentStatus::Failed, 'raw_payload' => $charge ?: null]);
            $locked->applicants
                ->where('status', ApplicantStatus::PendingPayment)
                ->each->transitionTo(ApplicantStatus::Draft);
        });
    }

    /** Refund itself is done in Tap's dashboard; this records it and issues the credit note. */
    public function markRefunded(Payment $payment, User $by): Invoice
    {
        if ($payment->status !== PaymentStatus::Paid) {
            throw new \InvalidArgumentException('Only paid payments can be refunded.');
        }

        $payment->update(['status' => PaymentStatus::Refunded, 'refunded_at' => now()]);

        return $this->invoices->creditNote($this->invoices->issue($payment));
    }
}
