@php($p = $invoice->payment)
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #111; }
        table { width: 100%; border-collapse: collapse; margin-top: 16px; }
        th, td { border-bottom: 1px solid #ddd; padding: 6px; text-align: left; }
        .right { text-align: right; }
        h1 { font-size: 20px; margin: 0; }
    </style>
</head>
<body>
    @if (file_exists(public_path('images/boc-logo.png')))
        <img src="{{ public_path('images/boc-logo.png') }}" height="60">
    @endif
    <h1>{{ $invoice->type === 'credit_note' ? 'Credit Note' : 'Invoice' }} {{ $invoice->number }}</h1>
    <p>
        {{ config('visa.invoice.company_name') }}<br>{{ config('visa.invoice.address') }}
    </p>
    <p>
        Issued: {{ $invoice->issued_at->format('d M Y') }}<br>
        Billed to: {{ $p->user->name }} ({{ $p->user->email }})<br>
        Event: {{ $p->event->name }}
        @if ($invoice->references) <br>Refers to invoice: {{ $invoice->references->number }} @endif
    </p>

    <table>
        <thead><tr><th>Applicant</th><th>Passport</th><th class="right">Amount ({{ $p->currency }})</th></tr></thead>
        <tbody>
        @foreach ($p->applicants as $a)
            <tr>
                <td>Visa fee — {{ $a->full_name }}</td>
                <td>{{ $a->passport_number }}</td>
                <td class="right">{{ number_format((float) $invoice->subtotal / max($p->applicants->count(), 1), 3) }}</td>
            </tr>
        @endforeach
        </tbody>
        <tfoot>
            <tr><td colspan="2" class="right">Subtotal</td><td class="right">{{ number_format((float) $invoice->subtotal, 3) }}</td></tr>
            @if ((float) $invoice->vat_amount > 0)
                <tr><td colspan="2" class="right">VAT ({{ $p->event->vat_rate }}%)</td><td class="right">{{ number_format((float) $invoice->vat_amount, 3) }}</td></tr>
            @endif
            <tr><th colspan="2" class="right">Total</th><th class="right">{{ number_format((float) $invoice->total, 3) }}</th></tr>
        </tfoot>
    </table>

    <p>
        Paid via Tap{{ $p->payment_method ? ' ('.$p->payment_method.')' : '' }} on {{ $p->paid_at?->format('d M Y H:i') }}<br>
        Tap charge ID: {{ $p->tap_charge_id }}
    </p>
</body>
</html>
