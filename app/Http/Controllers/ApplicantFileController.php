<?php

namespace App\Http\Controllers;

use App\Models\Applicant;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

class ApplicantFileController
{
    public function __invoke(Applicant $applicant, string $kind)
    {
        Gate::authorize($kind === 'visa' ? 'downloadVisa' : 'viewDocuments', $applicant);

        $path = match ($kind) {
            'passport' => $applicant->passport_scan_path,
            'photo' => $applicant->photo_path,
            'visa' => $applicant->visa_pdf_path,
        };
        abort_unless($path, 404);

        return redirect(Storage::disk(config('visa.disk'))->temporaryUrl($path, now()->addMinutes(5)));
    }
}
