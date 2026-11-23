<?php

namespace App\Filament\Resources\Applicants;

use App\Enums\ApplicantStatus;
use App\Enums\Role;
use App\Filament\Exports\ApplicantExporter;
use App\Filament\Resources\Applicants\Pages\ListApplicants;
use App\Filament\Resources\Applicants\Pages\ViewApplicant;
use App\Models\Applicant;
use BackedEnum;
use Filament\Actions\BulkAction;
use Filament\Actions\ExportAction;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ZipArchive;

class ApplicantResource extends Resource
{
    protected static ?string $model = Applicant::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static ?string $navigationLabel = 'Visa applications';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->whereNotIn('status', [ApplicantStatus::Draft->value, ApplicantStatus::PendingPayment->value])
            ->with(['event', 'assignedProcessor']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('full_name')->searchable()->sortable(),
                TextColumn::make('nationality')->sortable(),
                TextColumn::make('passport_number')->searchable()->copyable(),
                TextColumn::make('event.name')->label('Event'),
                TextColumn::make('status')->badge(),
                TextColumn::make('assignedProcessor.name')->label('Claimed by')->placeholder('—'),
                TextColumn::make('submitted_at')->label('Waiting')->since()->sortable(),
            ])
            ->defaultSort('submitted_at')
            ->filters([
                SelectFilter::make('status')->options(ApplicantStatus::class)->default(ApplicantStatus::Submitted->value),
                SelectFilter::make('event')->relationship('event', 'name'),
                SelectFilter::make('nationality')->options(fn () => Applicant::query()->distinct()->orderBy('nationality')->pluck('nationality', 'nationality')->all()),
                Filter::make('mine')->label('Claimed by me')->query(fn (Builder $q) => $q->where('assigned_processor_id', auth()->id())),
            ])
            ->headerActions([
                ExportAction::make()->exporter(ApplicantExporter::class)
                    ->visible(fn () => auth()->user()->hasRole(Role::Admin)),
            ])
            ->recordActions([ViewAction::make()])
            ->toolbarActions([
                BulkAction::make('downloadDocuments')
                    ->label('Download passports & photos')
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->action(fn (Collection $records) => static::zipDocuments($records))
                    ->deselectRecordsAfterCompletion(),
            ]);
    }

    public static function zipDocuments(Collection $records)
    {
        $disk = Storage::disk(config('visa.disk'));
        $zipPath = tempnam(sys_get_temp_dir(), 'docs');
        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        foreach ($records as $a) {
            $folder = Str::slug($a->full_name).'-'.$a->passport_number;
            foreach (['passport' => $a->passport_scan_path, 'photo' => $a->photo_path] as $kind => $path) {
                if ($path && $disk->exists($path)) {
                    $zip->addFromString("{$folder}/{$kind}.".pathinfo($path, PATHINFO_EXTENSION), $disk->get($path));
                }
            }
        }
        $zip->close();

        return response()->download($zipPath, 'applicant-documents-'.now()->format('Ymd-His').'.zip')->deleteFileAfterSend();
    }

    public static function infolist(Schema $schema): Schema
    {
        $isPdf = fn (Applicant $r) => str_ends_with((string) $r->passport_scan_path, '.pdf');

        return $schema->components([
            Section::make('Applicant')->columns(3)->schema([
                TextEntry::make('full_name'),
                TextEntry::make('email')->copyable(),
                TextEntry::make('nationality'),
                TextEntry::make('date_of_birth')->date(),
                TextEntry::make('mobile')->url(fn (Applicant $r) => 'tel:'.$r->mobile),
                TextEntry::make('whatsapp')->url(fn (Applicant $r) => 'https://wa.me/'.preg_replace('/\D/', '', $r->whatsapp))->openUrlInNewTab(),
                TextEntry::make('event.name')->label('Event'),
                TextEntry::make('user.name')->label('Submitted by'),
                TextEntry::make('status')->badge(),
            ]),
            Section::make('Passport')->columns(3)->schema([
                TextEntry::make('passport_number')->copyable(),
                TextEntry::make('issuing_country')->label('Country of issue'),
                TextEntry::make('passport_issue_date')->date(),
                TextEntry::make('passport_expiry_date')->date(),
                TextEntry::make('gov_reference_no')->label('Gov. reference')->placeholder('—'),
                TextEntry::make('correction_note')->placeholder('—'),
            ]),
            Section::make('Documents')->columns(2)->schema([
                ImageEntry::make('passport_preview')->label('Passport scan')
                    ->state(fn (Applicant $r) => $r->passport_scan_path ? route('applicant.file', [$r, 'passport']) : null)
                    ->imageHeight(320)->visible(fn (Applicant $r) => ! $isPdf($r)),
                TextEntry::make('passport_pdf')->label('Passport scan')->state('Open PDF')
                    ->url(fn (Applicant $r) => route('applicant.file', [$r, 'passport']))->openUrlInNewTab()
                    ->visible($isPdf),
                ImageEntry::make('photo_preview')->label('Photo')
                    ->state(fn (Applicant $r) => $r->photo_path ? route('applicant.file', [$r, 'photo']) : null)
                    ->imageHeight(320),
                TextEntry::make('visa')->label('Issued visa')->state('Download visa PDF')
                    ->url(fn (Applicant $r) => route('applicant.file', [$r, 'visa']))->openUrlInNewTab()
                    ->visible(fn (Applicant $r) => filled($r->visa_pdf_path)),
            ]),
            Section::make('History')->schema([
                RepeatableEntry::make('statusLogs')->hiddenLabel()->columns(4)->schema([
                    TextEntry::make('created_at')->dateTime()->hiddenLabel(),
                    TextEntry::make('to_status')->badge()->hiddenLabel(),
                    TextEntry::make('user.name')->placeholder('System')->hiddenLabel(),
                    TextEntry::make('note')->placeholder('')->hiddenLabel(),
                ]),
            ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListApplicants::route('/'),
            'view' => ViewApplicant::route('/{record}'),
        ];
    }
}
