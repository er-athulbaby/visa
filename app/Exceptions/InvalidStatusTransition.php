<?php

namespace App\Exceptions;

use App\Enums\ApplicantStatus;
use RuntimeException;

class InvalidStatusTransition extends RuntimeException
{
    public function __construct(ApplicantStatus $from, ApplicantStatus $to)
    {
        parent::__construct("Cannot move an application from {$from->label()} to {$to->label()}.");
    }
}
