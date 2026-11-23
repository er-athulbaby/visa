<?php

namespace App\Filament\Resources\Payments;

use App\Enums\PaymentStatus;
use App\Filament\Exports\PaymentExporter;
use App\Filament\Resources\Payments\Pages\ListPayments;
use App\Filament\Resources\Payments\Pages\ViewPayment;
use App\Models\Invoice;
use App\Models\Payment;
use BackedEnum;
use Filament\Actions\ExportAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PaymentResource extends Resource
{
    protected static ?string $model = Payment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->label('Date')->dateTime()->sortable(),
                TextColumn::make('user.name')->label('Payer')->searchable(),
                TextColumn::make('event.name')->label('Event'),
                TextColumn::make('applicants_count')->counts('applicants')->label('Applicants'),
                TextColumn::make('amount')->numeric(3)->prefix('BHD ')
                    ->summarize(Sum::make()->label('Paid total')->numeric(3)
                        ->query(fn ($query) => $query->where('status', PaymentStatus::Paid->value))),
                TextColumn::make('tap_charge_id')->label('Tap charge')->searchable()->copyable(),
                TextColumn::make('status')->badge(),
            ])
            ->defaultSort('created_at', 'desc')
            ->groups([Group::make('event.name')->label('Event')])
            ->filters([
                SelectFilter::make('status')->options(PaymentStatus::class),
                SelectFilter::make('event')->relationship('event', 'name'),
                Filter::make('created_at')
                    ->schema([DatePicker::make('from'), DatePicker::make('until')])
                    ->query(fn (Builder $q, array $data) => $q
                        ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
                        ->when($data['until'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '<=', $d))),
            ])
            ->headerActions([ExportAction::make()->exporter(PaymentExporter::class)])
            ->recordActions([ViewAction::make()]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Payment')->columns(3)->schema([
                TextEntry::make('user.name')->label('Payer'),
                TextEntry::make('user.email')->label('Payer email'),
                TextEntry::make('event.name')->label('Event'),
                TextEntry::make('amount')->numeric(3)->prefix('BHD '),
                TextEntry::make('status')->badge(),
                TextEntry::make('payment_method')->placeholder('—'),
                TextEntry::make('tap_charge_id')->copyable(),
                TextEntry::make('paid_at')->dateTime()->placeholder('—'),
                TextEntry::make('refunded_at')->dateTime()->placeholder('—'),
            ]),
            Section::make('Applicants covered')->schema([
                RepeatableEntry::make('applicants')->hiddenLabel()->columns(2)->schema([
                    TextEntry::make('full_name')->hiddenLabel(),
                    TextEntry::make('status')->badge()->hiddenLabel(),
                ]),
            ]),
            Section::make('Invoices')->schema([
                RepeatableEntry::make('invoices')->hiddenLabel()->columns(3)->schema([
                    TextEntry::make('number')->hiddenLabel()
                        ->url(fn (Invoice $record) => route('invoices.download', $record))->openUrlInNewTab(),
                    TextEntry::make('type')->hiddenLabel(),
                    TextEntry::make('total')->numeric(3)->prefix('BHD ')->hiddenLabel(),
                ]),
            ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPayments::route('/'),
            'view' => ViewPayment::route('/{record}'),
        ];
    }
}
