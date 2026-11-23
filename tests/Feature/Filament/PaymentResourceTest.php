<?php

use App\Enums\PaymentStatus;
use App\Filament\Resources\Payments\Pages\ListPayments;
use App\Filament\Resources\Payments\Pages\ViewPayment;
use App\Models\Payment;
use App\Models\User;
use App\Notifications\PaymentConfirmed;
use App\Services\InvoiceService;
use App\Services\PaymentService;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake(config('visa.disk'));
    $this->actingAs(User::factory()->finance()->create());
    $this->paid = Payment::factory()->create(['status' => PaymentStatus::Paid, 'paid_at' => now()]);
});

it('lists every transaction for finance', function () {
    $failed = Payment::factory()->create(['status' => PaymentStatus::Failed]);

    Livewire::test(ListPayments::class)->assertCanSeeTableRecords([$this->paid, $failed]);
});

it('resends the invoice', function () {
    Notification::fake();
    app(InvoiceService::class)->issue($this->paid);

    Livewire::test(ViewPayment::class, ['record' => $this->paid->getRouteKey()])->callAction('resendInvoice');

    Notification::assertSentTo($this->paid->user, PaymentConfirmed::class);
});

it('marks a paid payment refunded with a credit note', function () {
    Livewire::test(ViewPayment::class, ['record' => $this->paid->getRouteKey()])->callAction('markRefunded');

    expect($this->paid->fresh()->status)->toBe(PaymentStatus::Refunded)
        ->and($this->paid->invoices()->where('type', 'credit_note')->exists())->toBeTrue();
});

it('turns a lost refund race into a notification, not a 500', function () {
    $this->mock(PaymentService::class)->shouldReceive('markRefunded')->andThrow(new InvalidArgumentException('Only paid payments can be refunded.'));

    Livewire::test(ViewPayment::class, ['record' => $this->paid->getRouteKey()])
        ->callAction('markRefunded')->assertNotified();

    expect($this->paid->invoices()->where('type', 'credit_note')->exists())->toBeFalse();
});

it('hides markRefunded once the payment is no longer paid', function () {
    $this->paid->update(['status' => PaymentStatus::Refunded]);

    Livewire::test(ViewPayment::class, ['record' => $this->paid->getRouteKey()])->assertActionHidden('markRefunded');
});

it('hides payments from processors', function () {
    expect(User::factory()->processor()->create()->can('viewAny', Payment::class))->toBeFalse();
});

it('shows the stats widget to finance only', function () {
    Livewire::test(\App\Filament\Widgets\PaymentStats::class)->assertSee('BHD 30.000');
    expect(\App\Filament\Widgets\PaymentStats::canView())->toBeTrue();

    $this->actingAs(User::factory()->processor()->create());
    expect(\App\Filament\Widgets\PaymentStats::canView())->toBeFalse();
});
