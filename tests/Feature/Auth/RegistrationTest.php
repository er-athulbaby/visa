<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_screen_can_be_rendered(): void
    {
        $response = $this->get('/register');

        $response->assertStatus(200);
    }

    public function test_new_users_can_register(): void
    {
        $response = Volt::test('auth.register')
            ->set('name', 'Test User')
            ->set('email', 'test@example.com')
            ->set('password', 'password')
            ->set('password_confirmation', 'password')
            ->call('register');

        $response
            ->assertHasNoErrors()
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticated();
    }

    public function test_registration_is_rate_limited_per_ip(): void
    {
        for ($i = 0; $i < 5; $i++) {
            Volt::test('auth.register')->set('email', 'bad')->call('register');
        }

        Volt::test('auth.register')
            ->set('name', 'Test User')
            ->set('email', 'ok@example.com')
            ->set('password', 'password')
            ->set('password_confirmation', 'password')
            ->call('register')
            ->assertHasErrors(['email'])
            ->assertNoRedirect();

        $this->assertGuest();
    }
}
