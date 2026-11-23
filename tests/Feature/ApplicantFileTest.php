<?php

use App\Enums\ApplicantStatus;
use App\Models\Applicant;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake(config('visa.disk'));
    Storage::disk(config('visa.disk'))->put('applicants/test-passport.jpg', 'x');
    $this->applicant = Applicant::factory()->create();
});

it('lets the owner open their passport scan', function () {
    $this->actingAs($this->applicant->user)
        ->get(route('applicant.file', [$this->applicant, 'passport']))
        ->assertRedirect();
});

it('lets processors open documents', function () {
    $this->actingAs(User::factory()->processor()->create())
        ->get(route('applicant.file', [$this->applicant, 'passport']))
        ->assertRedirect();
});

it('blocks other applicants and finance', function (string $role) {
    $user = $role === 'finance' ? User::factory()->finance()->create() : User::factory()->create();

    $this->actingAs($user)
        ->get(route('applicant.file', [$this->applicant, 'passport']))
        ->assertForbidden();
})->with(['applicant', 'finance']);

it('only serves the visa once issued', function () {
    $this->actingAs($this->applicant->user)
        ->get(route('applicant.file', [$this->applicant, 'visa']))
        ->assertForbidden();

    $this->applicant->forceFill(['status' => ApplicantStatus::VisaIssued, 'visa_pdf_path' => 'visas/v.pdf'])->save();
    Storage::disk(config('visa.disk'))->put('visas/v.pdf', 'pdf');

    $this->actingAs($this->applicant->user)
        ->get(route('applicant.file', [$this->applicant, 'visa']))
        ->assertRedirect();
});
