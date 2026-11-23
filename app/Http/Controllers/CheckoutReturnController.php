<?php

namespace App\Http\Controllers;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Services\PaymentService;
use App\Services\TapClient;
use Illuminate\Support\Facades\Gate;

class CheckoutReturnController
{
    public function __invoke(Payment $payment, TapClient $tap, PaymentService $payments)
    {
        Gate::authorize('view', $payment);

        if ($payment->status === PaymentStatus::Initiated && $payment->tap_charge_id) {
            rescue(fn () => $payments->syncFromCharge($payment, $tap->retrieveCharge($payment->tap_charge_id)));
            $payment->refresh();
        }

        return view('checkout.return', ['payment' => $payment]);
    }
}
