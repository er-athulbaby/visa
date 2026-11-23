<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Invoice extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:3',
            'vat_amount' => 'decimal:3',
            'total' => 'decimal:3',
            'issued_at' => 'datetime',
            'emailed_at' => 'datetime',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function references(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'references_invoice_id');
    }
}
