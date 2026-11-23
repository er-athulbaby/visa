<?php

namespace App\Filament\Pages;

use Filament\Auth\Pages\Login as BaseLogin;
use Illuminate\Contracts\Support\Htmlable;

class Login extends BaseLogin
{
    public function getSubheading(): string|Htmlable|null
    {
        return parent::getSubheading()
            ?? 'Staff panel — for BOC administrators, visa processors and finance.';
    }
}
