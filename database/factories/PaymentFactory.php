<?php

namespace Database\Factories;

use App\Enums\PaymentStatus;
use App\Models\Event;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class PaymentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'event_id' => Event::factory(),
            'tap_charge_id' => 'chg_'.Str::random(20),
            'amount' => 30,
            'currency' => 'BHD',
            'status' => PaymentStatus::Initiated,
        ];
    }
}
