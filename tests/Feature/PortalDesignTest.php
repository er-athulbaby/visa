<?php

use App\Enums\PaymentStatus;
use App\Livewire\Dashboard;
use App\Models\Applicant;
use App\Models\Event;
use App\Models\Payment;
use App\Models\User;
use Livewire\Livewire;

it('renders the BOC layout without starter-kit branding', function () {
    $this->actingAs(User::factory()->create())->get(route('dashboard'))
        ->assertOk()
        ->assertSee('images/boc-logo.png')
        ->assertSee('Event Visa Portal')
        ->assertSee('aria-current="page"', false)
        ->assertSee('Dashboard')
        ->assertDontSee('Laravel Starter Kit')
        ->assertDontSee('Documentation');
});

it('shows the selected count and total for an event', function () {
    $me = User::factory()->create();
    $event = Event::factory()->create(['visa_fee' => 30]);
    $drafts = Applicant::factory()->count(3)->for($me)->for($event)->create();

    Livewire::actingAs($me)->test(Dashboard::class)
        ->assertSee('0 selected')
        ->set('selected', [(string) $drafts[0]->id, (string) $drafts[1]->id])
        ->assertSee('2 selected')
        ->assertSee('BHD 60.000');
});

it('shows failed and pending return states', function () {
    $me = User::factory()->create();
    $failed = Payment::factory()->for($me)->create(['status' => PaymentStatus::Failed]);
    $pending = Payment::factory()->for($me)->create(['status' => PaymentStatus::Initiated, 'tap_charge_id' => null]);

    $this->actingAs($me)->get(route('checkout.return', $failed))->assertSee("Payment didn't go through", false)->assertSee('Try again');
    $this->actingAs($me)->get(route('checkout.return', $pending))->assertSee('Confirming your payment')->assertSee("Please don't pay again", false);
});

it('hides the support line unless configured', function () {
    $me = User::factory()->create();
    $failed = Payment::factory()->for($me)->create(['status' => PaymentStatus::Failed]);

    config(['visa.support_email' => null]);
    $this->actingAs($me)->get(route('checkout.return', $failed))->assertDontSee('Need help?');

    config(['visa.support_email' => 'help@boc.test']);
    $this->actingAs($me)->get(route('checkout.return', $failed))->assertSee('Need help?')->assertSee('help@boc.test');
});
