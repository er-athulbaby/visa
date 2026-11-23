<?php

use App\Enums\ApplicantStatus;
use App\Enums\PaymentStatus;
use App\Models\Applicant;
use App\Models\Event;
use App\Models\Payment;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

function pendingPayment(int $minutesAgo): Payment
{
    $payment = Payment::factory()->create(['tap_charge_id' => 'chg_R1', 'created_at' => now()->subMinutes($minutesAgo)]);
    $payment->applicants()->attach(Applicant::factory()->status(ApplicantStatus::PendingPayment)->create(['event_id' => $payment->event_id]));

    return $payment;
}

it('marks captured charges paid', function () {
    Bus::fake();
    Http::fake(['api.tap.company/v2/charges/chg_R1' => Http::response(['id' => 'chg_R1', 'status' => 'CAPTURED'])]);
    $payment = pendingPayment(10);

    $this->artisan('payments:reconcile')->assertSuccessful();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Paid);
});

it('expires abandoned checkouts after the timeout', function () {
    Http::fake(['api.tap.company/v2/charges/chg_R1' => Http::response(['id' => 'chg_R1', 'status' => 'INITIATED'])]);
    $payment = pendingPayment(31);

    $this->artisan('payments:reconcile')->assertSuccessful();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Failed)
        ->and($payment->applicants->first()->fresh()->status)->toBe(ApplicantStatus::Draft);
});

it('leaves recent pending checkouts alone', function () {
    Http::fake(['api.tap.company/v2/charges/chg_R1' => Http::response(['id' => 'chg_R1', 'status' => 'INITIATED'])]);
    $payment = pendingPayment(10);

    $this->artisan('payments:reconcile')->assertSuccessful();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Initiated);
});

it('purges passport scans and photos after the retention period', function () {
    Storage::fake(config('visa.disk'));
    $disk = Storage::disk(config('visa.disk'));
    $old = Applicant::factory()->for(Event::factory()->state(['end_date' => today()->subDays(91)]))->create();
    $recent = Applicant::factory()->for(Event::factory()->state(['end_date' => today()->subDays(10)]))->create(['passport_scan_path' => 'applicants/keep.jpg']);
    $disk->put($old->passport_scan_path, 'x');
    $disk->put('applicants/keep.jpg', 'x');

    $this->artisan('files:purge')->assertSuccessful();

    $disk->assertMissing($old->passport_scan_path);
    $disk->assertExists('applicants/keep.jpg');
    expect($old->fresh()->passport_scan_path)->toBeNull()
        ->and($old->fresh()->photo_path)->toBeNull()
        ->and($recent->fresh()->passport_scan_path)->toBe('applicants/keep.jpg');
});
