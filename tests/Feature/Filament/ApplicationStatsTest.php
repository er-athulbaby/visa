<?php

use App\Enums\ApplicantStatus;
use App\Filament\Widgets\ApplicationStats;
use App\Models\Applicant;
use App\Models\User;
use Livewire\Livewire;

it('is visible to processors and admins but not finance', function () {
    $this->actingAs(User::factory()->processor()->create());
    expect(ApplicationStats::canView())->toBeTrue();

    $this->actingAs(User::factory()->admin()->create());
    expect(ApplicationStats::canView())->toBeTrue();

    $this->actingAs(User::factory()->finance()->create());
    expect(ApplicationStats::canView())->toBeFalse();
});

it('shows the application counts', function () {
    $me = User::factory()->processor()->create();
    $this->actingAs($me);
    Applicant::factory()->count(2)->create(['status' => ApplicantStatus::Submitted]);
    Applicant::factory()->create(['status' => ApplicantStatus::InReview, 'assigned_processor_id' => $me->id]);
    Applicant::factory()->create(['status' => ApplicantStatus::InReview, 'assigned_processor_id' => User::factory()->processor()->create()->id]);
    Applicant::factory()->create(['status' => ApplicantStatus::Applied]);
    Applicant::factory()->count(3)->create(['status' => ApplicantStatus::VisaIssued]);

    Livewire::test(ApplicationStats::class)
        ->assertSeeInOrder(['Waiting', '2', 'Claimed by me', '1', 'Applied', '1', 'Visas issued', '3']);
});
