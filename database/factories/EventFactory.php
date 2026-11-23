<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class EventFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->words(3, true);

        return [
            'name' => Str::title($name),
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(5)),
            'start_date' => today()->addDays(30),
            'end_date' => today()->addDays(35),
            'application_deadline' => today()->addDays(20),
            'visa_fee' => 30,
            'currency' => 'BHD',
            'vat_rate' => 0,
            'is_open' => true,
        ];
    }
}
