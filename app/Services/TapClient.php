<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\User;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class TapClient
{
    private const THREE_DECIMAL_CURRENCIES = ['BHD', 'KWD', 'OMR', 'JOD'];

    public function createCharge(Payment $payment, User $payer): array
    {
        return $this->http()->post('/charges', [
            'amount' => (float) $payment->amount,
            'currency' => $payment->currency,
            'customer_initiated' => true,
            'threeDSecure' => true,
            'description' => "Visa fee - {$payment->event->name}",
            'reference' => ['transaction' => "pay_{$payment->id}", 'order' => (string) $payment->id],
            'customer' => ['first_name' => $payer->name, 'email' => $payer->email],
            'source' => ['id' => 'src_all'],
            'post' => ['url' => route('tap.webhook')],
            'redirect' => ['url' => route('checkout.return', $payment)],
        ])->throw()->json();
    }

    public function retrieveCharge(string $chargeId): array
    {
        return $this->http()->get("/charges/{$chargeId}")->throw()->json();
    }

    public function signature(array $payload): string
    {
        $decimals = in_array($payload['currency'] ?? '', self::THREE_DECIMAL_CURRENCIES, true) ? 3 : 2;

        $string = 'x_id'.($payload['id'] ?? '')
            .'x_amount'.number_format((float) ($payload['amount'] ?? 0), $decimals, '.', '')
            .'x_currency'.($payload['currency'] ?? '')
            .'x_gateway_reference'.($payload['reference']['gateway'] ?? '')
            .'x_payment_reference'.($payload['reference']['payment'] ?? '')
            .'x_status'.($payload['status'] ?? '')
            .'x_created'.($payload['transaction']['created'] ?? '');

        return hash_hmac('sha256', $string, (string) config('visa.tap.secret_key'));
    }

    public function isValidWebhook(array $payload, ?string $hashstring): bool
    {
        return $hashstring !== null && hash_equals($this->signature($payload), $hashstring);
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl(config('visa.tap.base_url'))
            ->withToken((string) config('visa.tap.secret_key'))
            ->acceptJson()
            ->timeout(20);
    }
}
