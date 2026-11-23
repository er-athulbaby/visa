<?php

use App\Models\Payment;
use App\Services\TapClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    // Routes are added in Task 10; register stubs so route() resolves here.
    Route::post('/webhooks/tap', fn () => null)->name('tap.webhook');
    Route::get('/checkout/{payment}/return', fn () => null)->name('checkout.return');
    // Fluent ->name() is not indexed until the name lookup table is refreshed.
    app('router')->getRoutes()->refreshNameLookups();
});

it('creates a charge with the payment amount and redirect urls', function () {
    Http::fake(['api.tap.company/v2/charges' => Http::response(['id' => 'chg_1', 'transaction' => ['url' => 'https://checkout.tap.company/x']])]);
    $payment = Payment::factory()->create(['amount' => 60, 'tap_charge_id' => null]);

    $charge = app(TapClient::class)->createCharge($payment, $payment->user);

    expect($charge['id'])->toBe('chg_1');
    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer sk_test_dummy')
        && $request['amount'] == 60
        && $request['currency'] === 'BHD'
        && $request['source']['id'] === 'src_all'
        && $request['reference']['order'] === (string) $payment->id
        && str_ends_with($request['redirect']['url'], "/checkout/{$payment->id}/return"));
});

it('retrieves a charge', function () {
    Http::fake(['api.tap.company/v2/charges/chg_1' => Http::response(['id' => 'chg_1', 'status' => 'CAPTURED'])]);

    expect(app(TapClient::class)->retrieveCharge('chg_1')['status'])->toBe('CAPTURED');
});

it('validates webhook signatures', function () {
    $tap = app(TapClient::class);
    $payload = ['id' => 'chg_1', 'amount' => 60, 'currency' => 'BHD', 'status' => 'CAPTURED',
        'reference' => ['gateway' => 'g1', 'payment' => 'p1'], 'transaction' => ['created' => '1700000000000']];

    expect($tap->isValidWebhook($payload, $tap->signature($payload)))->toBeTrue()
        ->and($tap->isValidWebhook($payload, 'forged'))->toBeFalse()
        ->and($tap->isValidWebhook($payload, null))->toBeFalse();
});
