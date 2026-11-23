<?php

use App\Enums\ApplicantStatus;
use App\Enums\PaymentStatus;
use App\Jobs\GenerateInvoice;
use App\Livewire\Dashboard;
use App\Models\Applicant;
use App\Models\Event;
use App\Models\Payment;
use App\Models\User;
use App\Services\PaymentService;
use App\Services\TapClient;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->event = Event::factory()->create(['visa_fee' => 30]);
    $this->drafts = Applicant::factory()->count(2)->for($this->user)->for($this->event)->create();
});

function fakeTap(string $status = 'CAPTURED'): void
{
    Http::fake([
        'api.tap.company/v2/charges' => Http::response(['id' => 'chg_TEST1', 'transaction' => ['url' => 'https://checkout.tap.company/pay/x']]),
        'api.tap.company/v2/charges/*' => Http::response(['id' => 'chg_TEST1', 'status' => $status, 'amount' => 60, 'currency' => 'BHD', 'source' => ['payment_method' => 'VISA']]),
    ]);
}

it('starts a group checkout for the selected drafts', function () {
    fakeTap();

    Livewire::actingAs($this->user)->test(Dashboard::class)
        ->set('selected', $this->drafts->pluck('id')->all())
        ->call('pay', $this->event->id)
        ->assertRedirect('https://checkout.tap.company/pay/x');

    $payment = Payment::sole();
    expect((string) $payment->amount)->toBe('60.000')
        ->and($payment->tap_charge_id)->toBe('chg_TEST1')
        ->and($payment->applicants)->toHaveCount(2)
        ->and($this->drafts->first()->fresh()->status)->toBe(ApplicantStatus::PendingPayment);
});

it('ignores applicants that are not my drafts', function () {
    fakeTap();
    $foreign = Applicant::factory()->for($this->event)->create();

    Livewire::actingAs($this->user)->test(Dashboard::class)
        ->set('selected', [$foreign->id])
        ->call('pay', $this->event->id)
        ->assertHasErrors('selected');

    expect(Payment::count())->toBe(0);
});

it('rolls back when Tap is down', function () {
    Http::fake(['api.tap.company/*' => Http::response([], 500)]);

    Livewire::actingAs($this->user)->test(Dashboard::class)
        ->set('selected', $this->drafts->pluck('id')->all())
        ->call('pay', $this->event->id)
        ->assertHasErrors('selected');

    expect(Payment::count())->toBe(0)
        ->and($this->drafts->first()->fresh()->status)->toBe(ApplicantStatus::Draft);
});

function startedPayment($test): Payment
{
    fakeTap();
    app(PaymentService::class)->start($test->user, $test->event, $test->drafts);

    return Payment::sole();
}

it('marks the payment paid from a signed webhook, once', function () {
    Bus::fake([GenerateInvoice::class]);
    $payment = startedPayment($this);
    $payload = ['id' => 'chg_TEST1', 'amount' => 60, 'currency' => 'BHD', 'status' => 'CAPTURED',
        'reference' => ['gateway' => 'g', 'payment' => 'p'], 'transaction' => ['created' => '1']];
    $hash = app(TapClient::class)->signature($payload);

    $this->postJson(route('tap.webhook'), $payload, ['hashstring' => $hash])->assertNoContent();
    $this->postJson(route('tap.webhook'), $payload, ['hashstring' => $hash])->assertNoContent();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Paid)
        ->and($payment->fresh()->payment_method)->toBe('VISA')
        ->and($this->drafts->first()->fresh()->status)->toBe(ApplicantStatus::Submitted);
    Bus::assertDispatchedTimes(GenerateInvoice::class, 1);
});

it('rejects an unsigned webhook', function () {
    $this->postJson(route('tap.webhook'), ['id' => 'chg_TEST1'], ['hashstring' => 'nope'])->assertForbidden();
});

it('returns applicants to draft when the charge fails', function () {
    $payment = startedPayment($this);

    app(PaymentService::class)->syncFromCharge($payment, ['status' => 'DECLINED']);

    expect($payment->fresh()->status)->toBe(PaymentStatus::Failed)
        ->and($this->drafts->first()->fresh()->status)->toBe(ApplicantStatus::Draft);
});

it('syncs on the return page without trusting the browser', function () {
    Bus::fake([GenerateInvoice::class]);
    $payment = startedPayment($this);

    $this->actingAs($this->user)->get(route('checkout.return', $payment))
        ->assertOk()->assertSee('Payment received');

    expect($payment->fresh()->status)->toBe(PaymentStatus::Paid);
});

it('hides another user’s return page', function () {
    $payment = startedPayment($this);

    $this->actingAs(User::factory()->create())->get(route('checkout.return', $payment))->assertForbidden();
});

it('refunds a paid payment with a credit note', function () {
    \Illuminate\Support\Facades\Storage::fake(config('visa.disk'));
    $payment = startedPayment($this);
    app(PaymentService::class)->syncFromCharge($payment, ['status' => 'CAPTURED']);

    $credit = app(PaymentService::class)->markRefunded($payment->fresh(), User::factory()->finance()->create());

    expect($payment->fresh()->status)->toBe(PaymentStatus::Refunded)
        ->and($credit->type)->toBe('credit_note');
});

it('recovers a capture that arrives after the payment was marked failed', function () {
    Bus::fake([GenerateInvoice::class]);
    $payment = startedPayment($this);
    $service = app(PaymentService::class);
    $service->syncFromCharge($payment, ['status' => 'DECLINED']);

    $service->syncFromCharge($payment->fresh(), ['status' => 'CAPTURED']);
    $service->syncFromCharge($payment->fresh(), ['status' => 'CAPTURED']);

    expect($payment->fresh()->status)->toBe(PaymentStatus::Paid)
        ->and($this->drafts->first()->fresh()->status)->toBe(ApplicantStatus::Submitted)
        ->and($this->drafts->last()->fresh()->status)->toBe(ApplicantStatus::Submitted);
    Bus::assertDispatchedTimes(GenerateInvoice::class, 1);
});

it('logs critical when a late capture covers applicants already in another payment', function () {
    Bus::fake([GenerateInvoice::class]);
    $payment = startedPayment($this);
    $service = app(PaymentService::class);
    $service->syncFromCharge($payment, ['status' => 'DECLINED']);
    $other = $this->drafts->last()->fresh();
    $other->transitionTo(ApplicantStatus::PendingPayment, $this->user);
    Log::spy();

    $service->syncFromCharge($payment->fresh(), ['status' => 'CAPTURED']);

    expect($payment->fresh()->status)->toBe(PaymentStatus::Paid)
        ->and($this->drafts->first()->fresh()->status)->toBe(ApplicantStatus::Submitted)
        ->and($other->fresh()->status)->toBe(ApplicantStatus::PendingPayment);
    Log::shouldHaveReceived('critical')->once();
});

it('trusts the Tap API over the posted webhook body', function () {
    fakeTap('DECLINED');
    app(PaymentService::class)->start($this->user, $this->event, $this->drafts);
    $payment = Payment::sole();
    $payload = ['id' => 'chg_TEST1', 'amount' => 60, 'currency' => 'BHD', 'status' => 'CAPTURED',
        'reference' => ['gateway' => 'g', 'payment' => 'p'], 'transaction' => ['created' => '1']];

    $this->postJson(route('tap.webhook'), $payload, ['hashstring' => app(TapClient::class)->signature($payload)])->assertNoContent();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Failed)
        ->and($this->drafts->first()->fresh()->status)->toBe(ApplicantStatus::Draft);
});
