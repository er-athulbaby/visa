<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Services\PaymentService;
use App\Services\TapClient;
use Illuminate\Http\Request;

class TapWebhookController
{
    public function __invoke(Request $request, TapClient $tap, PaymentService $payments)
    {
        abort_unless($tap->isValidWebhook($request->all(), $request->header('hashstring')), 403);

        $payment = Payment::where('tap_charge_id', $request->input('id'))->first();

        if ($payment) {
            // Trust Tap's API, not the posted body.
            $payments->syncFromCharge($payment, $tap->retrieveCharge($payment->tap_charge_id));
        }

        return response()->noContent();
    }
}
