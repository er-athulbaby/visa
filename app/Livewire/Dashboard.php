<?php

namespace App\Livewire;

use App\Enums\ApplicantStatus;
use App\Exceptions\InvalidStatusTransition;
use App\Models\Applicant;
use App\Models\Event;
use App\Services\PaymentService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
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

    public function pay(int $eventId, PaymentService $payments)
    {
        $event = Event::findOrFail($eventId);
        $applicants = auth()->user()->applicants()
            ->whereIn('id', $this->selected)
            ->where('event_id', $event->id)
            ->where('status', ApplicantStatus::Draft)
            ->get();

        if ($applicants->isEmpty()) {
            $this->addError('selected', 'Tick at least one draft applicant for this event.');

            return null;
        }

        try {
            return $this->redirect($payments->start(auth()->user(), $event, $applicants));
        } catch (RequestException|ConnectionException $e) {
            report($e);
            $this->addError('selected', 'The payment gateway is unavailable. Please try again in a few minutes.');
        } catch (\InvalidArgumentException|InvalidStatusTransition $e) {
            $this->addError('selected', $e->getMessage());
        }

        return null;
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
