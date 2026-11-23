<?php

namespace App\Enums;

enum PaymentStatus: string implements \Filament\Support\Contracts\HasColor, \Filament\Support\Contracts\HasLabel
{
    case Initiated = 'initiated';
    case Paid = 'paid';
    case Failed = 'failed';
    case Refunded = 'refunded';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function getLabel(): string
    {
        return $this->label();
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Initiated => 'gray',
            self::Paid => 'success',
            self::Failed => 'danger',
            self::Refunded => 'warning',
        };
    }
}
