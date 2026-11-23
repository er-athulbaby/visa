<?php

namespace App\Console\Commands;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Services\PaymentService;
use App\Services\TapClient;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;

class ReconcilePayments extends Command
{
    protected $signature = 'payments:reconcile';

    protected $description = 'Sync initiated Tap payments and expire abandoned checkouts';

    public function handle(TapClient $tap, PaymentService $payments): int
    {
        $timeout = now()->subMinutes(config('visa.checkout_timeout_minutes'));

        Payment::query()
            ->where('status', PaymentStatus::Initiated)
            ->where('created_at', '<=', now()->subMinutes(5))
            ->each(function (Payment $payment) use ($tap, $payments, $timeout) {
                $charge = [];
                if ($payment->tap_charge_id) {
                    try {
                        $charge = $tap->retrieveCharge($payment->tap_charge_id);
                    } catch (RequestException|ConnectionException $e) {
                        report($e);

                        return; // try again next run
                    }
                    $payments->syncFromCharge($payment, $charge);
                }

                if ($payment->fresh()->status === PaymentStatus::Initiated && $payment->created_at->lte($timeout)) {
                    $payments->markFailed($payment, $charge);
                }
            });

        return self::SUCCESS;
    }
}
