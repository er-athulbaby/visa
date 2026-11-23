<?php

use App\Enums\ApplicantStatus;
use App\Livewire\Dashboard;
use App\Models\Applicant;
use App\Models\Event;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

it('redirects guests to the login page', function () {
    $this->get('/dashboard')->assertRedirect('/login');
});

it('shows open events and only my applicants', function () {
    $me = User::factory()->create();
    $event = Event::factory()->create(['name' => 'Gulf Games 2027']);
    Applicant::factory()->for($me)->for($event)->create(['full_name' => 'My Athlete']);
    Applicant::factory()->create(['full_name' => 'Someone Else']);

    $this->actingAs($me)->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Gulf Games 2027')
        ->assertSee('My Athlete')
        ->assertDontSee('Someone Else');
});

it('requires a verified email', function () {
    $this->actingAs(User::factory()->unverified()->create())
        ->get(route('dashboard'))
        ->assertRedirect(route('verification.notice'));
});

it('lets the owner delete a draft and removes its files', function () {
    Storage::fake(config('visa.disk'));
    $me = User::factory()->create();
    Storage::disk(config('visa.disk'))->put('applicants/a.jpg', 'x');
    Storage::disk(config('visa.disk'))->put('applicants/b.jpg', 'x');
    $a = Applicant::factory()->for($me)->status(ApplicantStatus::Draft)
        ->create(['passport_scan_path' => 'applicants/a.jpg', 'photo_path' => 'applicants/b.jpg']);

    Livewire::actingAs($me)->test(Dashboard::class)->call('delete', $a->id);

    expect(Applicant::find($a->id))->toBeNull();
    Storage::disk(config('visa.disk'))->assertMissing(['applicants/a.jpg', 'applicants/b.jpg']);
});

it('forbids deleting another user’s applicant', function () {
    $a = Applicant::factory()->status(ApplicantStatus::Draft)->create();

    Livewire::actingAs(User::factory()->create())->test(Dashboard::class)->call('delete', $a->id)->assertForbidden();
    expect(Applicant::find($a->id))->not->toBeNull();
});

it('forbids deleting a non-draft applicant', function () {
    $me = User::factory()->create();
    $a = Applicant::factory()->for($me)->status(ApplicantStatus::Submitted)->create();

    Livewire::actingAs($me)->test(Dashboard::class)->call('delete', $a->id)->assertForbidden();
});

it('keeps staff out of the applicant portal', function () {
    $this->actingAs(\App\Models\User::factory()->processor()->create())
        ->get(route('dashboard'))
        ->assertRedirect(route('filament.admin.pages.dashboard'));
});
