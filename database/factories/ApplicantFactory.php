<?php

namespace Database\Factories;

use App\Enums\ApplicantStatus;
use App\Models\Event;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ApplicantFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'event_id' => Event::factory(),
            'full_name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'nationality' => 'IND',
            'date_of_birth' => '1995-05-20',
            'passport_number' => strtoupper(fake()->unique()->bothify('?#######')),
            'issuing_country' => 'IND',
            'passport_issue_date' => today()->subYears(2),
            'passport_expiry_date' => today()->addYears(5),
            'mobile' => '+919800000000',
            'whatsapp' => '+919800000000',
            'passport_scan_path' => 'applicants/test-passport.jpg',
            'photo_path' => 'applicants/test-photo.jpg',
            'status' => ApplicantStatus::Draft,
        ];
    }

    public function status(ApplicantStatus $status): static
    {
        return $this->state(['status' => $status]);
    }
}
