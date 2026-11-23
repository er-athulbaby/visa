<?php

use App\Enums\Role;
use App\Filament\Resources\Events\Pages\CreateEvent;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\Event;
use App\Models\User;
use Livewire\Livewire;

it('lets admins create an event', function () {
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test(CreateEvent::class)
        ->fillForm([
            'name' => 'Gulf Games 2027', 'slug' => 'gulf-games-2027',
            'start_date' => '2027-03-01', 'end_date' => '2027-03-10', 'application_deadline' => '2027-02-01',
            'visa_fee' => 30, 'vat_rate' => 0, 'is_open' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Event::where('slug', 'gulf-games-2027')->exists())->toBeTrue();
});

it('keeps events and staff management admin-only', function (string $role) {
    $user = User::factory()->{$role}()->create();

    expect($user->can('viewAny', Event::class))->toBeFalse()
        ->and($user->can('viewAny', User::class))->toBeFalse();
})->with(['processor', 'finance']);

it('lists only staff accounts and creates a processor', function () {
    $this->actingAs(User::factory()->admin()->create());
    $applicant = User::factory()->create();

    Livewire::test(ListUsers::class)->assertCanNotSeeTableRecords([$applicant]);

    Livewire::test(CreateUser::class)
        ->fillForm(['name' => 'Pat Processor', 'email' => 'pat@boc.bh', 'role' => Role::Processor->value, 'password' => 'a-Strong-pass-123', 'is_active' => true])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(User::where('email', 'pat@boc.bh')->first()->role)->toBe(Role::Processor);
});
