<?php

use App\Models\Applicant;
use App\Models\Event;
use Illuminate\Database\QueryException;

it('rejects the same passport twice in one event', function () {
    $event = Event::factory()->create();
    Applicant::factory()->for($event)->create(['passport_number' => 'X1234567']);

    expect(fn () => Applicant::factory()->for($event)->create(['passport_number' => 'X1234567']))
        ->toThrow(QueryException::class);
});

it('allows the same passport in different events', function () {
    Applicant::factory()->create(['passport_number' => 'X1234567']);
    Applicant::factory()->create(['passport_number' => 'X1234567']);

    expect(Applicant::count())->toBe(2);
});

it('knows whether an event accepts applications', function () {
    expect(Event::factory()->make(['is_open' => true, 'application_deadline' => today()])->isAcceptingApplications())->toBeTrue()
        ->and(Event::factory()->make(['is_open' => false])->isAcceptingApplications())->toBeFalse()
        ->and(Event::factory()->make(['is_open' => true, 'application_deadline' => today()->subDay()])->isAcceptingApplications())->toBeFalse();
});

it('requires passports valid six months past the event end', function () {
    $event = Event::factory()->make(['end_date' => '2027-03-10']);

    expect($event->minimumPassportExpiry()->toDateString())->toBe('2027-09-10');
});
