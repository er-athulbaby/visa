<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\Event;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Local demo data only — never run in production.
 * php artisan db:seed --class=DemoSeeder
 *
 * Logins (all share the password below):
 *   Staff panel  http://127.0.0.1:8000/admin   admin@demo.test / processor@demo.test / finance@demo.test
 *   Applicant    http://127.0.0.1:8000/login   applicant@demo.test
 */
class DemoSeeder extends Seeder
{
    public const PASSWORD = 'Demo-Visa-2026!';

    public function run(): void
    {
        foreach ([
            ['Demo Admin', 'admin@demo.test', Role::Admin],
            ['Demo Processor', 'processor@demo.test', Role::Processor],
            ['Demo Finance', 'finance@demo.test', Role::Finance],
            ['Demo Applicant', 'applicant@demo.test', Role::Applicant],
        ] as [$name, $email, $role]) {
            User::updateOrCreate(['email' => $email], [
                'name' => $name,
                'password' => self::PASSWORD,
                'role' => $role,
                'is_active' => true,
                'email_verified_at' => now(),
            ]);
        }

        Event::updateOrCreate(['slug' => 'gulf-games-2027'], [
            'name' => 'Gulf Games 2027',
            'start_date' => today()->addMonths(3),
            'end_date' => today()->addMonths(3)->addDays(10),
            'application_deadline' => today()->addMonths(2),
            'visa_fee' => 30,
            'currency' => 'BHD',
            'vat_rate' => 0,
            'is_open' => true,
        ]);
    }
}
