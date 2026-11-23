@php($p = $invoice->payment)
@php($isCredit = $invoice->type === 'credit_note')
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 48px 54px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #111827; }
        table { width: 100%; border-collapse: collapse; }
        .muted { color: #374151; }
        .label { font-size: 9px; font-weight: bold; letter-spacing: 0.6px; color: #374151; text-transform: uppercase; }
        .right { text-align: right; }
        .head { border-bottom: 3px solid #111827; }
        .lines th { border-bottom: 1.5px solid #111827; padding: 8px 0; font-size: 9px; text-align: left; text-transform: uppercase; }
        .lines td { border-bottom: 1px solid #E5E7EB; padding: 11px 0; }
        .lines th.right, .lines td.right { text-align: right; }
    </style>
</head>
<body>
    <table class="head">
        <tr>
            <td style="padding-bottom: 18px; width: 60%; vertical-align: middle;">
                <table style="width: auto;"><tr>
                    <td style="vertical-align: middle; padding-right: 14px;">
                        @if (file_exists(public_path('images/boc-logo.png')))
                            <img src="{{ public_path('images/boc-logo.png') }}" style="height: 90px;">
                        @endif
                    </td>
                    <td style="vertical-align: middle; line-height: 1.5;">
                        <strong style="font-size: 14px;">{{ config('visa.invoice.company_name') }}</strong><br>
                        <span class="muted">{!! nl2br(e(config('visa.invoice.address'))) !!}</span>
                    </td>
                </tr></table>
            </td>
            <td class="right" style="vertical-align: middle; padding-bottom: 18px;">
                <strong style="font-size: 22px; letter-spacing: 1px;">{{ $isCredit ? 'CREDIT NOTE' : 'INVOICE' }}</strong><br>
                <strong style="font-size: 12px;">{{ $invoice->number }}</strong>
            </td>
        </tr>
    </table>

    <table style="margin-top: 26px;">
        <tr>
            <td style="width: 33%; vertical-align: top; line-height: 1.6;">
                <span class="label">Billed to</span><br>
                <strong>{{ $p->user->name }}</strong><br>
                <span class="muted">{{ $p->user->email }}</span>
            </td>
            <td style="width: 34%; vertical-align: top; line-height: 1.6;">
                <span class="label">Event</span><br>
                <strong>{{ $p->event->name }}</strong>
                @if ($p->event->start_date && $p->event->end_date)
                    <br><span class="muted">{{ $p->event->start_date->format('d M') }} – {{ $p->event->end_date->format('d M Y') }}</span>
                @endif
            </td>
            <td style="width: 33%; vertical-align: top; line-height: 1.6;">
                <span class="label">Issued</span><br>
                <strong>{{ $invoice->issued_at->format('d M Y') }}</strong>
                @if ($invoice->references)
                    <br><span class="muted">Refers to invoice: {{ $invoice->references->number }}</span>
                @endif
            </td>
        </tr>
    </table>

    <table class="lines" style="margin-top: 28px;">
        <thead><tr><th>Description</th><th>Passport</th><th class="right">Amount ({{ $p->currency }})</th></tr></thead>
        <tbody>
        @foreach ($p->applicants as $a)
            <tr>
                <td>Visa fee — {{ $a->full_name }}</td>
                <td class="muted">{{ $a->passport_number }}</td>
                <td class="right">{{ number_format((float) $invoice->subtotal / max($p->applicants->count(), 1), 3) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <table style="width: 260px; margin-left: auto; margin-top: 18px;">
        <tr><td class="muted" style="padding: 4px 0;">Subtotal</td><td class="right">{{ number_format((float) $invoice->subtotal, 3) }}</td></tr>
        @if ((float) $invoice->vat_amount > 0)
            <tr><td class="muted" style="padding: 4px 0;">VAT ({{ $p->event->vat_rate }}%)</td><td class="right">{{ number_format((float) $invoice->vat_amount, 3) }}</td></tr>
        @endif
        <tr>
            <td style="border-top: 1.5px solid #111827; padding-top: 8px; font-size: 13px; font-weight: bold;">Total</td>
            <td class="right" style="border-top: 1.5px solid #111827; padding-top: 8px; font-size: 13px; font-weight: bold;">{{ $p->currency }} {{ number_format((float) $invoice->total, 3) }}</td>
        </tr>
    </table>

    <div class="muted" style="margin-top: 60px; border-top: 1px solid #E5E7EB; padding-top: 14px; line-height: 1.6;">
        Paid via Tap{{ $p->payment_method ? ' ('.$p->payment_method.')' : '' }} on {{ $p->paid_at?->format('d M Y H:i') }}<br>
        Tap charge ID: {{ $p->tap_charge_id }}<br>
        <span style="font-size: 9px;">This invoice was generated electronically and is valid without a signature.</span>
    </div>
</body>
</html>
