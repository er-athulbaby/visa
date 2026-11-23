<?php

namespace App\Enums;

enum Role: string
{
    case Applicant = 'applicant';
    case Admin = 'admin';
    case Processor = 'processor';
    case Finance = 'finance';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
