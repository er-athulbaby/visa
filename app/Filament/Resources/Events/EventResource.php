<?php

namespace App\Filament\Resources\Events;

use App\Filament\Resources\Events\Pages\CreateEvent;
use App\Filament\Resources\Events\Pages\EditEvent;
use App\Filament\Resources\Events\Pages\ListEvents;
use App\Models\Event;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class EventResource extends Resource
{
    protected static ?string $model = Event::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(255)
                ->live(onBlur: true)
                ->afterStateUpdated(fn (Set $set, ?string $state, string $operation) => $operation === 'create' ? $set('slug', Str::slug((string) $state)) : null),
            TextInput::make('slug')->required()->alphaDash()->unique(ignoreRecord: true),
            DatePicker::make('start_date')->required(),
            DatePicker::make('end_date')->required()->afterOrEqual('start_date'),
            DatePicker::make('application_deadline')->required()->beforeOrEqual('end_date'),
            TextInput::make('visa_fee')->label('Visa fee per applicant')->numeric()->minValue(0)->required()->prefix('BHD'),
            TextInput::make('vat_rate')->label('VAT rate')->numeric()->minValue(0)->maxValue(100)->required()->default(0)->suffix('%'),
            Toggle::make('is_open')->label('Open for applications')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('start_date')->date()->sortable(),
                TextColumn::make('end_date')->date(),
                TextColumn::make('application_deadline')->date(),
                TextColumn::make('visa_fee')->numeric(3)->prefix('BHD '),
                TextColumn::make('applicants_count')->counts('applicants')->label('Applicants'),
                IconColumn::make('is_open')->boolean()->label('Open'),
            ])
            ->defaultSort('start_date', 'desc')
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEvents::route('/'),
            'create' => CreateEvent::route('/create'),
            'edit' => EditEvent::route('/{record}/edit'),
        ];
    }
}
