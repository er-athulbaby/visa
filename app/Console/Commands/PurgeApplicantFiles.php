<?php

namespace App\Console\Commands;

use App\Models\Applicant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class PurgeApplicantFiles extends Command
{
    protected $signature = 'files:purge';

    protected $description = 'Delete passport scans and photos once the retention period after an event has passed';

    public function handle(): int
    {
        $cutoff = today()->subDays(config('visa.file_retention_days'));
        $disk = Storage::disk(config('visa.disk'));

        Applicant::query()
            ->whereHas('event', fn ($q) => $q->whereDate('end_date', '<', $cutoff))
            ->where(fn ($q) => $q->whereNotNull('passport_scan_path')->orWhereNotNull('photo_path'))
            ->each(function (Applicant $applicant) use ($disk) {
                $paths = array_filter([$applicant->passport_scan_path, $applicant->photo_path]);
                if (! $disk->delete($paths)) {
                    report(new \RuntimeException("Could not delete files for applicant {$applicant->id}"));

                    return; // keep the paths so the next run retries
                }
                $applicant->forceFill(['passport_scan_path' => null, 'photo_path' => null])->save();
            });

        return self::SUCCESS;
    }
}
