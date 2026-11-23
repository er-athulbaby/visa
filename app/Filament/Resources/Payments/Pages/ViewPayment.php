<?php

namespace App\Filament\Resources\Payments\Pages;

use App\Enums\PaymentStatus;
use App\Filament\Resources\Payments\PaymentResource;
use App\Notifications\PaymentConfirmed;
use App\Services\PaymentService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Exceptions\Halt;

class ViewPayment extends ViewRecord
{
    protected static string $resource = PaymentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('resendInvoice')
                ->label('Resend invoice')
                ->visible(fn () => $this->record->invoices()->where('type', 'invoice')->exists())
                ->action(function () {
                    $invoice = $this->record->invoices()->where('type', 'invoice')->first();
                    $this->record->user->notify(new PaymentConfirmed($invoice));
                    Notification::make()->title('Invoice sent')->success()->send();
                }),

            Action::make('markRefunded')
                ->label('Mark refunded')->color('danger')
                ->requiresConfirmation()
                ->modalDescription('Do this only after the refund has been made in the Tap dashboard. A credit note will be issued.')
                ->visible(fn () => $this->record->status === PaymentStatus::Paid)
                ->action(function (PaymentService $payments) {
                    try {
                        $credit = $payments->markRefunded($this->record, auth()->user());
                    } catch (\InvalidArgumentException) {
                        $this->record->refresh();
                        Notification::make()->title('Only paid payments can be refunded; this one was already changed.')->danger()->send();

                        throw new Halt;
                    }
                    $this->record->refresh();
                    Notification::make()->title("Credit note {$credit->number} issued")->success()->send();
                }),
        ];
    }
}
