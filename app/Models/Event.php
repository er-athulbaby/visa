<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class Event extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'application_deadline' => 'date',
            'visa_fee' => 'decimal:3',
            'vat_rate' => 'decimal:2',
            'is_open' => 'boolean',
        ];
    }

    public function applicants(): HasMany
    {
        return $this->hasMany(Applicant::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function isAcceptingApplications(): bool
    {
        return $this->is_open && today()->lte($this->application_deadline);
    }

    public function scopeAcceptingApplications(Builder $query): void
    {
        $query->where('is_open', true)->whereDate('application_deadline', '>=', today());
    }

    public function minimumPassportExpiry(): Carbon
    {
        return $this->end_date->copy()->addMonthsNoOverflow(6);
    }
}
