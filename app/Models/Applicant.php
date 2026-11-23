<?php

namespace App\Models;

use App\Enums\ApplicantStatus;
use App\Exceptions\InvalidStatusTransition;
use App\Notifications\ApplicantStatusChanged;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

class Applicant extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'passport_issue_date' => 'date',
            'passport_expiry_date' => 'date',
            'status' => ApplicantStatus::class,
            'ocr_raw' => 'array',
            'submitted_at' => 'datetime',
            'applied_at' => 'datetime',
            'issued_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function assignedProcessor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_processor_id');
    }

    public function payments(): BelongsToMany
    {
        return $this->belongsToMany(Payment::class);
    }

    public function statusLogs(): HasMany
    {
        return $this->hasMany(StatusLog::class)->latest('id');
    }

    private const NOTIFY_ON = [
        ApplicantStatus::InReview,
        ApplicantStatus::NeedsCorrection,
        ApplicantStatus::Applied,
        ApplicantStatus::VisaIssued,
        ApplicantStatus::Rejected,
    ];

    public function transitionTo(
        ApplicantStatus $to,
        ?User $by = null,
        ?string $note = null,
        array $attributes = [],
        bool $override = false,
    ): void {
        if ($override && blank($note)) {
            throw new \InvalidArgumentException('A status override requires a note.');
        }

        DB::transaction(function () use ($to, $by, $note, $attributes, $override) {
            // Re-read under lock so two processors can't act on the same stale status.
            // value() applies the enum cast, so this is already an ApplicantStatus.
            $current = static::query()->whereKey($this->getKey())->lockForUpdate()->value('status');

            if (! $override && ($current !== $this->status || ! $current->canTransitionTo($to))) {
                throw new InvalidStatusTransition($this->status, $to);
            }

            $stamps = match ($to) {
                ApplicantStatus::Submitted => ['submitted_at' => now()],
                ApplicantStatus::Applied => ['applied_at' => now()],
                ApplicantStatus::VisaIssued => ['issued_at' => now()],
                default => [],
            };

            $this->forceFill(['status' => $to] + $attributes + $stamps)->save();

            $this->statusLogs()->create([
                'from_status' => $current,
                'to_status' => $to,
                'user_id' => $by?->id,
                'note' => $note,
            ]);
        });

        if (in_array($to, self::NOTIFY_ON, true)) {
            $this->user->notify(new ApplicantStatusChanged($this));

            if (strcasecmp($this->email, $this->user->email) !== 0) {
                Notification::route('mail', $this->email)->notify(new ApplicantStatusChanged($this));
            }
        }
    }
}
