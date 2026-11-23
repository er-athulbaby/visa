<?php

namespace App\Livewire;

use App\Models\Applicant;
use App\Models\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;

class Dashboard extends Component
{
    /** @var list<int> applicant ids ticked for checkout */
    public array $selected = [];

    public function delete(int $applicantId): void
    {
        $applicant = Applicant::findOrFail($applicantId);
        Gate::authorize('delete', $applicant);

        Storage::disk(config('visa.disk'))->delete(array_filter([$applicant->passport_scan_path, $applicant->photo_path]));
        $applicant->delete();
    }

    public function render()
    {
        $user = auth()->user();

        return view('livewire.dashboard', [
            'events' => Event::acceptingApplications()->orderBy('start_date')->get(),
            'applicantsByEvent' => $user->applicants()->with('event')->latest()->get()->groupBy('event_id'),
            'payments' => $user->payments()->with(['event', 'invoices'])->latest()->get(),
        ]);
    }
}
