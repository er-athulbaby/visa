<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

class InvoiceDownloadController
{
    public function __invoke(Invoice $invoice)
    {
        Gate::authorize('view', $invoice);

        return Storage::disk(config('visa.disk'))->download($invoice->pdf_path, "{$invoice->number}.pdf");
    }
}
