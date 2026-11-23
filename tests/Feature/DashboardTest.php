<?php

use App\Models\Applicant;
use App\Models\Event;
use App\Models\User;

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
