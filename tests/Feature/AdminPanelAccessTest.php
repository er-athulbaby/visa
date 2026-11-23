<?php

use App\Models\User;

it('blocks applicants from the staff panel', function () {
    $this->actingAs(User::factory()->create())->get('/admin')->assertForbidden();
});

it('blocks deactivated staff', function () {
    $this->actingAs(User::factory()->processor()->create(['is_active' => false]))->get('/admin')->assertForbidden();
});

it('lets active staff in (to 2FA setup or dashboard)', function (string $role) {
    $user = User::factory()->{$role}()->create();

    expect($this->actingAs($user)->get('/admin')->status())->not->toBe(403);
})->with(['admin', 'processor', 'finance']);

it('enforces the admin IP allowlist when configured', function () {
    config(['visa.admin_ip_allowlist' => ['10.0.0.0/8']]);
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->get('/admin')->assertForbidden(); // tests run from 127.0.0.1
    expect($this->actingAs($admin)->withServerVariables(['REMOTE_ADDR' => '10.1.2.3'])->get('/admin')->status())->not->toBe(403);
});

it('promotes a user to admin from the CLI', function () {
    $user = User::factory()->create(['email' => 'ops@boc.bh']);

    $this->artisan('visa:make-admin', ['email' => 'ops@boc.bh'])->assertSuccessful();

    expect($user->fresh()->role)->toBe(\App\Enums\Role::Admin);
});
