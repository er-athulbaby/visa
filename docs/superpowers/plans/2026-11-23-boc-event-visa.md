# BOC Event Visa System Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A Laravel app where foreign participants (or delegation managers) apply for Bahrain visas per BOC event, upload passport + photo with MRZ OCR prefill, pay via Tap, and receive the issued visa; BOC staff (Admin / Processor / Finance) work applications and payments in Filament.

**Architecture:** One Laravel 12 monolith. Applicant portal = Livewire full-page components on `/`; staff panel = Filament 4 on `/admin`. All business rules live in models/services (`Applicant::transitionTo`, `PaymentService`, `InvoiceService`, `MrzParser`) so Livewire and Filament stay thin and tests hit the services directly. Files on a private disk (`local` in dev/test, `s3` in prod) served only via authorized redirects to 5-minute temporary URLs.

**Tech Stack:** Laravel 12, Livewire 3 (via `laravel/livewire-starter-kit`), Filament 4, Pest, MySQL 8 (prod) / SQLite (dev + tests), Tesseract OCR, Tap Payments API v2, barryvdh/laravel-dompdf, league/iso3166, AWS me-south-1 (EC2, S3, SES).

**Spec:** `docs/superpowers/specs/2026-11-23-boc-event-visa-design.md`

## Global Constraints

- Production PHP is **8.3** → `composer config platform.php 8.3.0` so the lock file installs there (dev machine runs PHP 8.5).
- Currency **BHD**, money as `decimal(10,3)`; flat `visa_fee` per applicant per event; total = `visa_fee × applicant count`.
- Countries stored as **ISO-3166 alpha-3** (MRZ native format).
- Passport expiry must be **≥ event end_date + 6 months**; one passport number per event (unique `(event_id, passport_number)`).
- Status changes go **only** through `Applicant::transitionTo()`; never assign `status` directly outside factories.
- Tap webhook + reconciliation are the **only** things that mark payments paid; the browser redirect never changes state (it may trigger the same idempotent sync).
- Invoice numbers: `BOC-{YYYY}-{00001}`; credit notes `BOC-CN-{YYYY}-{00001}`; VAT rate per event, default 0.
- Finance never sees passport scans or photos. Applicants only see their own records.
- Files never public: always `route('applicant.file', ...)` → policy check → `temporaryUrl(5 min)`.
- Notifications go through Laravel Notification classes with `via()` = `['mail']` (WhatsApp channel later).
- Staff panel: 2FA required, optional IP allowlist (`ADMIN_IP_ALLOWLIST`).
- Passport/photo files purged `VISA_FILE_RETENTION_DAYS` (default **90**) after event end.

## File Structure

```
app/
  Enums/            ApplicantStatus.php, PaymentStatus.php, Role.php
  Models/           User, Event, Applicant, Payment, Invoice, StatusLog
  Exceptions/       InvalidStatusTransition.php
  Services/         MrzParser.php, PassportOcr.php, ImageSanitizer.php,
                    TapClient.php, PaymentService.php, InvoiceService.php
  Jobs/             GenerateInvoice.php
  Notifications/    ApplicantStatusChanged.php, PaymentConfirmed.php
  Policies/         ApplicantPolicy, PaymentPolicy, InvoicePolicy, EventPolicy, UserPolicy
  Http/Controllers/ ApplicantFileController, InvoiceDownloadController,
                    TapWebhookController, CheckoutReturnController
  Http/Middleware/  RestrictAdminIp.php
  Livewire/         Dashboard.php, ApplicantForm.php
  Console/Commands/ ReconcilePayments, PurgeApplicantFiles, BackupDatabase, MakeAdmin
  Filament/         Resources/{Events,Users,Applicants,Payments}/..., Exports/..., Widgets/PaymentStats.php
config/visa.php
resources/views/    livewire/dashboard.blade.php, livewire/applicant-form.blade.php,
                    checkout/return.blade.php, invoices/pdf.blade.php
deploy/             nginx.conf, supervisor.conf, README.md, .env.production.example
```

---

### Task 0: Machine prerequisites (manual, no code)

- [ ] **Step 1: Install Composer** — https://getcomposer.org/Composer-Setup.exe (uses existing PHP 8.5). Verify: `composer -V`.
- [ ] **Step 2: Enable PHP extensions** in `php.ini`: `fileinfo`, `gd`, `zip`, `pdo_sqlite`, `sqlite3`, `mbstring`, `openssl`, `intl`. Verify: `php -m`.
- [ ] **Step 3: Install Tesseract** (only needed for manual OCR testing) — https://github.com/UB-Mannheim/tesseract/wiki. Verify: `tesseract --version`.
- [ ] **Step 4: Set git identity**
  ```powershell
  git config --global user.name "Your Name"
  git config --global user.email "you@boc.bh"
  ```
- [ ] **Step 5: Commit the spec and plan** (repo already initialised, remote `origin` = `https://github.com/er-athulbaby/visa.git`)
  ```bash
  git add docs && git commit -m "docs: BOC event visa design spec and implementation plan"
  ```

---

### Task 1: Scaffold Laravel app

**Files:**
- Create: whole Laravel skeleton in repo root
- Create: `config/visa.php`
- Modify: `.env.example`, `phpunit.xml`, `composer.json`

**Interfaces:**
- Produces: `config('visa.disk')`, `config('visa.tap.*')`, `config('visa.tesseract_path')`, `config('visa.file_retention_days')`, `config('visa.admin_ip_allowlist')`, `config('visa.checkout_timeout_minutes')`, `config('visa.invoice.*')`

- [ ] **Step 1: Create the starter kit in a temp folder and move it into the repo root**

```powershell
composer create-project laravel/livewire-starter-kit _app
Get-ChildItem _app -Force | Move-Item -Destination . -Force
Remove-Item _app -Recurse -Force
composer config platform.php 8.3.0
composer update --no-interaction
```

- [ ] **Step 2: Check the starter kit's Livewire major version**

Run: `composer show livewire/livewire | Select-String versions`
Expected: `3.x`. If it is `4.x`, use `filament/filament:"^5.0"` in Task 12 instead of `^4.0` — Filament 5's API is identical to v4 for everything in this plan.

- [ ] **Step 3: Install packages**

```powershell
composer require barryvdh/laravel-dompdf league/iso3166 league/flysystem-aws-s3-v3
npm install
npm run build
```

- [ ] **Step 4: Create `config/visa.php`**

```php
<?php

return [
    // Disk for passports, photos, visas, invoices. 'local' (storage/app/private) in dev, 's3' in prod.
    'disk' => env('VISA_DISK', 'local'),

    'tesseract_path' => env('TESSERACT_PATH', 'tesseract'),

    'tap' => [
        'secret_key' => env('TAP_SECRET_KEY'),
        'base_url' => env('TAP_BASE_URL', 'https://api.tap.company/v2'),
    ],

    'checkout_timeout_minutes' => (int) env('CHECKOUT_TIMEOUT_MINUTES', 30),

    'file_retention_days' => (int) env('VISA_FILE_RETENTION_DAYS', 90),

    // Comma-separated IPs/CIDRs. Empty = no restriction.
    'admin_ip_allowlist' => array_values(array_filter(array_map('trim', explode(',', (string) env('ADMIN_IP_ALLOWLIST', ''))))),

    'invoice' => [
        'prefix' => 'BOC',
        'company_name' => 'Bahrain Olympic Committee',
        'address' => env('INVOICE_ADDRESS', 'Manama, Kingdom of Bahrain'),
    ],
];
```

- [ ] **Step 5: Append to `.env.example`**

```
VISA_DISK=local
TESSERACT_PATH=tesseract
TAP_SECRET_KEY=
TAP_BASE_URL=https://api.tap.company/v2
CHECKOUT_TIMEOUT_MINUTES=30
VISA_FILE_RETENTION_DAYS=90
ADMIN_IP_ALLOWLIST=
INVOICE_ADDRESS="Manama, Kingdom of Bahrain"
```

- [ ] **Step 6: In `phpunit.xml` `<php>` block, add**

```xml
<env name="VISA_DISK" value="local"/>
<env name="TAP_SECRET_KEY" value="sk_test_dummy"/>
<env name="QUEUE_CONNECTION" value="sync"/>
```

Confirm `DB_CONNECTION=sqlite` and `DB_DATABASE=:memory:` are already present there.

- [ ] **Step 7: Make sure `tests/Pest.php` uses `RefreshDatabase` for Feature tests**

It must contain (add if missing):

```php
pest()->extend(Tests\TestCase::class)
    ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Feature');
```

- [ ] **Step 8: Note the layout component**

Open `resources/views/dashboard.blade.php`. It wraps content in a layout component (expected `<x-layouts.app ...>`, file `resources/views/components/layouts/app.blade.php`). Livewire full-page components in this plan rely on Livewire's default layout `components.layouts.app`. If the kit's layout lives elsewhere, set `'layout' => '<that view name>'` in `config/livewire.php` (`php artisan livewire:publish --config`).

- [ ] **Step 9: Run the kit's tests**

Run: `php artisan test`
Expected: all PASS.

- [ ] **Step 10: Commit**

```bash
git add -A && git commit -m "chore: scaffold Laravel livewire starter kit with visa config"
```

---

### Task 2: Enums, migrations, models, factories

**Files:**
- Create: `app/Enums/Role.php`, `app/Enums/ApplicantStatus.php`, `app/Enums/PaymentStatus.php`
- Create: migrations `..._add_role_to_users_table.php`, `..._create_events_table.php`, `..._create_applicants_table.php`, `..._create_payments_table.php`, `..._create_invoices_table.php`, `..._create_status_logs_table.php`
- Create: `app/Models/Event.php`, `Applicant.php`, `Payment.php`, `Invoice.php`, `StatusLog.php`
- Modify: `app/Models/User.php`, `database/factories/UserFactory.php`
- Create: `database/factories/EventFactory.php`, `ApplicantFactory.php`, `PaymentFactory.php`
- Test: `tests/Feature/ModelsTest.php`, `tests/Unit/ApplicantStatusTest.php`

**Interfaces:**
- Produces: `Role::{Applicant,Admin,Processor,Finance}`; `ApplicantStatus::{Draft,PendingPayment,Submitted,InReview,NeedsCorrection,Applied,VisaIssued,Rejected}` with `allowedNext(): array`, `canTransitionTo(self): bool`, `label(): string`, `isEditableByApplicant(): bool`; `PaymentStatus::{Initiated,Paid,Failed,Refunded}` with `label()`.
- Produces: `User::hasRole(Role ...$roles): bool`, `User::applicants()`, `User::payments()`; `Event::isAcceptingApplications(): bool`, `Event::scopeAcceptingApplications()`, `Event::minimumPassportExpiry(): Carbon`; relations listed below; factory states `UserFactory::admin()/processor()/finance()`, `ApplicantFactory::status(ApplicantStatus)`.

- [ ] **Step 1: Write the failing tests**

`tests/Unit/ApplicantStatusTest.php`:

```php
<?php

use App\Enums\ApplicantStatus;

it('allows only the agreed transitions', function () {
    expect(ApplicantStatus::Draft->canTransitionTo(ApplicantStatus::PendingPayment))->toBeTrue()
        ->and(ApplicantStatus::Draft->canTransitionTo(ApplicantStatus::VisaIssued))->toBeFalse()
        ->and(ApplicantStatus::InReview->canTransitionTo(ApplicantStatus::NeedsCorrection))->toBeTrue()
        ->and(ApplicantStatus::NeedsCorrection->canTransitionTo(ApplicantStatus::Submitted))->toBeTrue()
        ->and(ApplicantStatus::Applied->canTransitionTo(ApplicantStatus::Rejected))->toBeTrue()
        ->and(ApplicantStatus::VisaIssued->allowedNext())->toBe([])
        ->and(ApplicantStatus::Rejected->allowedNext())->toBe([]);
});

it('labels statuses for humans', function () {
    expect(ApplicantStatus::NeedsCorrection->label())->toBe('Needs Correction');
});
```

`tests/Feature/ModelsTest.php`:

```php
<?php

use App\Models\Applicant;
use App\Models\Event;
use Illuminate\Database\QueryException;

it('rejects the same passport twice in one event', function () {
    $event = Event::factory()->create();
    Applicant::factory()->for($event)->create(['passport_number' => 'X1234567']);

    expect(fn () => Applicant::factory()->for($event)->create(['passport_number' => 'X1234567']))
        ->toThrow(QueryException::class);
});

it('allows the same passport in different events', function () {
    Applicant::factory()->create(['passport_number' => 'X1234567']);
    Applicant::factory()->create(['passport_number' => 'X1234567']);

    expect(Applicant::count())->toBe(2);
});

it('knows whether an event accepts applications', function () {
    expect(Event::factory()->make(['is_open' => true, 'application_deadline' => today()])->isAcceptingApplications())->toBeTrue()
        ->and(Event::factory()->make(['is_open' => false])->isAcceptingApplications())->toBeFalse()
        ->and(Event::factory()->make(['is_open' => true, 'application_deadline' => today()->subDay()])->isAcceptingApplications())->toBeFalse();
});

it('requires passports valid six months past the event end', function () {
    $event = Event::factory()->make(['end_date' => '2027-03-10']);

    expect($event->minimumPassportExpiry()->toDateString())->toBe('2027-09-10');
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test tests/Unit/ApplicantStatusTest.php tests/Feature/ModelsTest.php`
Expected: FAIL — classes not found.

- [ ] **Step 3: Create enums**

`app/Enums/Role.php`:

```php
<?php

namespace App\Enums;

enum Role: string
{
    case Applicant = 'applicant';
    case Admin = 'admin';
    case Processor = 'processor';
    case Finance = 'finance';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
```

`app/Enums/ApplicantStatus.php`:

```php
<?php

namespace App\Enums;

enum ApplicantStatus: string
{
    case Draft = 'draft';
    case PendingPayment = 'pending_payment';
    case Submitted = 'submitted';
    case InReview = 'in_review';
    case NeedsCorrection = 'needs_correction';
    case Applied = 'applied';
    case VisaIssued = 'visa_issued';
    case Rejected = 'rejected';

    /** @return list<self> */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Draft => [self::PendingPayment],
            self::PendingPayment => [self::Draft, self::Submitted],
            self::Submitted => [self::InReview],
            self::InReview => [self::NeedsCorrection, self::Applied, self::Rejected],
            self::NeedsCorrection => [self::Submitted],
            self::Applied => [self::VisaIssued, self::Rejected],
            self::VisaIssued, self::Rejected => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedNext(), true);
    }

    public function isEditableByApplicant(): bool
    {
        return $this === self::Draft || $this === self::NeedsCorrection;
    }

    public function label(): string
    {
        return ucwords(str_replace('_', ' ', $this->value));
    }
}
```

`app/Enums/PaymentStatus.php`:

```php
<?php

namespace App\Enums;

enum PaymentStatus: string
{
    case Initiated = 'initiated';
    case Paid = 'paid';
    case Failed = 'failed';
    case Refunded = 'refunded';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
```

- [ ] **Step 4: Create migrations** (`php artisan make:migration <name>` for each, then replace the body)

`add_role_to_users_table`:

```php
public function up(): void
{
    Schema::table('users', function (Blueprint $table) {
        $table->string('role')->default('applicant')->index();
        $table->boolean('is_active')->default(true);
    });
}

public function down(): void
{
    Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['role', 'is_active']));
}
```

`create_events_table`:

```php
public function up(): void
{
    Schema::create('events', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->string('slug')->unique();
        $table->date('start_date');
        $table->date('end_date');
        $table->date('application_deadline');
        $table->decimal('visa_fee', 10, 3);
        $table->string('currency', 3)->default('BHD');
        $table->decimal('vat_rate', 5, 2)->default(0);
        $table->boolean('is_open')->default(true);
        $table->timestamps();
    });
}

public function down(): void
{
    Schema::dropIfExists('events');
}
```

`create_applicants_table`:

```php
public function up(): void
{
    Schema::create('applicants', function (Blueprint $table) {
        $table->id();
        $table->foreignId('user_id')->constrained()->cascadeOnDelete();
        $table->foreignId('event_id')->constrained()->restrictOnDelete();
        $table->string('full_name');
        $table->string('email');
        $table->string('nationality', 3);
        $table->date('date_of_birth');
        $table->string('passport_number', 20);
        $table->string('issuing_country', 3);
        $table->date('passport_issue_date');
        $table->date('passport_expiry_date');
        $table->string('mobile', 20);
        $table->string('whatsapp', 20);
        $table->string('passport_scan_path')->nullable();
        $table->string('photo_path')->nullable();
        $table->string('status')->default('draft')->index();
        $table->foreignId('assigned_processor_id')->nullable()->constrained('users')->nullOnDelete();
        $table->text('correction_note')->nullable();
        $table->text('rejection_reason')->nullable();
        $table->string('gov_reference_no')->nullable();
        $table->string('visa_pdf_path')->nullable();
        $table->json('ocr_raw')->nullable();
        $table->timestamp('submitted_at')->nullable();
        $table->timestamp('applied_at')->nullable();
        $table->timestamp('issued_at')->nullable();
        $table->timestamps();

        $table->unique(['event_id', 'passport_number']);
    });
}

public function down(): void
{
    Schema::dropIfExists('applicants');
}
```

`create_payments_table`:

```php
public function up(): void
{
    Schema::create('payments', function (Blueprint $table) {
        $table->id();
        $table->foreignId('user_id')->constrained()->restrictOnDelete();
        $table->foreignId('event_id')->constrained()->restrictOnDelete();
        $table->string('tap_charge_id')->nullable()->unique();
        $table->decimal('amount', 10, 3);
        $table->string('currency', 3)->default('BHD');
        $table->string('status')->default('initiated')->index();
        $table->string('payment_method')->nullable();
        $table->timestamp('paid_at')->nullable();
        $table->timestamp('refunded_at')->nullable();
        $table->json('raw_payload')->nullable();
        $table->timestamps();
    });

    Schema::create('applicant_payment', function (Blueprint $table) {
        $table->foreignId('applicant_id')->constrained()->cascadeOnDelete();
        $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
        $table->primary(['applicant_id', 'payment_id']);
    });
}

public function down(): void
{
    Schema::dropIfExists('applicant_payment');
    Schema::dropIfExists('payments');
}
```

`create_invoices_table`:

```php
public function up(): void
{
    Schema::create('invoices', function (Blueprint $table) {
        $table->id();
        $table->foreignId('payment_id')->constrained()->restrictOnDelete();
        $table->string('number')->unique();
        $table->string('type'); // invoice | credit_note
        $table->foreignId('references_invoice_id')->nullable()->constrained('invoices');
        $table->decimal('subtotal', 10, 3);
        $table->decimal('vat_amount', 10, 3)->default(0);
        $table->decimal('total', 10, 3);
        $table->string('pdf_path')->nullable();
        $table->timestamp('issued_at');
        $table->timestamps();
    });

    Schema::create('invoice_sequences', function (Blueprint $table) {
        $table->string('series')->primary(); // e.g. BOC-2026, BOC-CN-2026
        $table->unsignedInteger('last_number')->default(0);
    });
}

public function down(): void
{
    Schema::dropIfExists('invoice_sequences');
    Schema::dropIfExists('invoices');
}
```

`create_status_logs_table`:

```php
public function up(): void
{
    Schema::create('status_logs', function (Blueprint $table) {
        $table->id();
        $table->foreignId('applicant_id')->constrained()->cascadeOnDelete();
        $table->string('from_status')->nullable();
        $table->string('to_status');
        $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
        $table->text('note')->nullable();
        $table->timestamp('created_at')->useCurrent();
    });
}

public function down(): void
{
    Schema::dropIfExists('status_logs');
}
```

- [ ] **Step 5: Update `app/Models/User.php`**

Keep the kit's existing methods (e.g. `initials()`); make the class implement `MustVerifyEmail` and add:

```php
use App\Enums\Role;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Relations\HasMany;

class User extends Authenticatable implements MustVerifyEmail
{
    // existing traits...

    protected $fillable = ['name', 'email', 'password', 'role', 'is_active'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => Role::class,
            'is_active' => 'boolean',
        ];
    }

    public function hasRole(Role ...$roles): bool
    {
        return in_array($this->role, $roles, true);
    }

    public function applicants(): HasMany
    {
        return $this->hasMany(Applicant::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}
```

- [ ] **Step 6: Create models**

`app/Models/Event.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class Event extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'application_deadline' => 'date',
            'visa_fee' => 'decimal:3',
            'vat_rate' => 'decimal:2',
            'is_open' => 'boolean',
        ];
    }

    public function applicants(): HasMany
    {
        return $this->hasMany(Applicant::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function isAcceptingApplications(): bool
    {
        return $this->is_open && today()->lte($this->application_deadline);
    }

    public function scopeAcceptingApplications(Builder $query): void
    {
        $query->where('is_open', true)->whereDate('application_deadline', '>=', today());
    }

    public function minimumPassportExpiry(): Carbon
    {
        return $this->end_date->copy()->addMonthsNoOverflow(6);
    }
}
```

`app/Models/Applicant.php` (transition logic added in Task 3):

```php
<?php

namespace App\Models;

use App\Enums\ApplicantStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
}
```

`app/Models/Payment.php`:

```php
<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payment extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:3',
            'status' => PaymentStatus::class,
            'paid_at' => 'datetime',
            'refunded_at' => 'datetime',
            'raw_payload' => 'array',
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

    public function applicants(): BelongsToMany
    {
        return $this->belongsToMany(Applicant::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }
}
```

`app/Models/Invoice.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Invoice extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:3',
            'vat_amount' => 'decimal:3',
            'total' => 'decimal:3',
            'issued_at' => 'datetime',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function references(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'references_invoice_id');
    }
}
```

`app/Models/StatusLog.php`:

```php
<?php

namespace App\Models;

use App\Enums\ApplicantStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StatusLog extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'from_status' => ApplicantStatus::class,
            'to_status' => ApplicantStatus::class,
        ];
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(Applicant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
```

- [ ] **Step 7: Factories**

Add to `database/factories/UserFactory.php` (keep existing `definition()`; add `'role' => Role::Applicant` to it):

```php
public function admin(): static
{
    return $this->state(['role' => \App\Enums\Role::Admin]);
}

public function processor(): static
{
    return $this->state(['role' => \App\Enums\Role::Processor]);
}

public function finance(): static
{
    return $this->state(['role' => \App\Enums\Role::Finance]);
}
```

`database/factories/EventFactory.php`:

```php
<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class EventFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->words(3, true);

        return [
            'name' => Str::title($name),
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(5)),
            'start_date' => today()->addDays(30),
            'end_date' => today()->addDays(35),
            'application_deadline' => today()->addDays(20),
            'visa_fee' => 30,
            'currency' => 'BHD',
            'vat_rate' => 0,
            'is_open' => true,
        ];
    }
}
```

`database/factories/ApplicantFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Enums\ApplicantStatus;
use App\Models\Event;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ApplicantFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'event_id' => Event::factory(),
            'full_name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'nationality' => 'IND',
            'date_of_birth' => '1995-05-20',
            'passport_number' => strtoupper(fake()->unique()->bothify('?#######')),
            'issuing_country' => 'IND',
            'passport_issue_date' => today()->subYears(2),
            'passport_expiry_date' => today()->addYears(5),
            'mobile' => '+919800000000',
            'whatsapp' => '+919800000000',
            'passport_scan_path' => 'applicants/test-passport.jpg',
            'photo_path' => 'applicants/test-photo.jpg',
            'status' => ApplicantStatus::Draft,
        ];
    }

    public function status(ApplicantStatus $status): static
    {
        return $this->state(['status' => $status]);
    }
}
```

`database/factories/PaymentFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Enums\PaymentStatus;
use App\Models\Event;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class PaymentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'event_id' => Event::factory(),
            'tap_charge_id' => 'chg_'.Str::random(20),
            'amount' => 30,
            'currency' => 'BHD',
            'status' => PaymentStatus::Initiated,
        ];
    }
}
```

- [ ] **Step 8: Run tests to verify they pass**

Run: `php artisan test`
Expected: all PASS (kit tests + new ones).

- [ ] **Step 9: Commit**

```bash
git add -A && git commit -m "feat: data model for events, applicants, payments, invoices"
```

---

### Task 3: Status transitions, audit log, applicant notifications

**Files:**
- Create: `app/Exceptions/InvalidStatusTransition.php`, `app/Notifications/ApplicantStatusChanged.php`
- Modify: `app/Models/Applicant.php`
- Test: `tests/Feature/ApplicantTransitionTest.php`

**Interfaces:**
- Consumes: `ApplicantStatus`, `StatusLog`, `User`.
- Produces: `Applicant::transitionTo(ApplicantStatus $to, ?User $by = null, ?string $note = null, array $attributes = [], bool $override = false): void` — throws `InvalidStatusTransition` or `InvalidArgumentException` (override without note). Sends `ApplicantStatusChanged` for `InReview, NeedsCorrection, Applied, VisaIssued, Rejected`.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/ApplicantTransitionTest.php`:

```php
<?php

use App\Enums\ApplicantStatus;
use App\Exceptions\InvalidStatusTransition;
use App\Models\Applicant;
use App\Models\User;
use App\Notifications\ApplicantStatusChanged;
use Illuminate\Support\Facades\Notification;

beforeEach(fn () => Notification::fake());

it('moves through an allowed transition and logs it', function () {
    $processor = User::factory()->processor()->create();
    $applicant = Applicant::factory()->status(ApplicantStatus::Submitted)->create();

    $applicant->transitionTo(ApplicantStatus::InReview, $processor, attributes: ['assigned_processor_id' => $processor->id]);

    $fresh = $applicant->fresh();
    expect($fresh->status)->toBe(ApplicantStatus::InReview)
        ->and($fresh->assigned_processor_id)->toBe($processor->id);

    $log = $fresh->statusLogs()->first();
    expect($log->from_status)->toBe(ApplicantStatus::Submitted)
        ->and($log->to_status)->toBe(ApplicantStatus::InReview)
        ->and($log->user_id)->toBe($processor->id);
});

it('stamps milestone timestamps', function () {
    $applicant = Applicant::factory()->status(ApplicantStatus::PendingPayment)->create();

    $applicant->transitionTo(ApplicantStatus::Submitted);

    expect($applicant->fresh()->submitted_at)->not->toBeNull();
});

it('refuses a disallowed transition', function () {
    $applicant = Applicant::factory()->create();

    expect(fn () => $applicant->transitionTo(ApplicantStatus::VisaIssued))
        ->toThrow(InvalidStatusTransition::class);
    expect($applicant->fresh()->status)->toBe(ApplicantStatus::Draft);
});

it('refuses a transition from a stale in-memory status', function () {
    $applicant = Applicant::factory()->status(ApplicantStatus::Submitted)->create();
    $stale = Applicant::find($applicant->id);
    $applicant->transitionTo(ApplicantStatus::InReview);

    expect(fn () => $stale->transitionTo(ApplicantStatus::InReview))
        ->toThrow(InvalidStatusTransition::class);
});

it('allows an admin override only with a note', function () {
    $admin = User::factory()->admin()->create();
    $applicant = Applicant::factory()->status(ApplicantStatus::Rejected)->create();

    expect(fn () => $applicant->transitionTo(ApplicantStatus::InReview, $admin, override: true))
        ->toThrow(InvalidArgumentException::class);

    $applicant->transitionTo(ApplicantStatus::InReview, $admin, 'Rejected by mistake', override: true);
    expect($applicant->fresh()->status)->toBe(ApplicantStatus::InReview);
});

it('notifies the submitter and the applicant on a correction request', function () {
    $applicant = Applicant::factory()->status(ApplicantStatus::InReview)->create();

    $applicant->transitionTo(ApplicantStatus::NeedsCorrection, null, 'Photo background must be white', ['correction_note' => 'Photo background must be white']);

    Notification::assertSentTo($applicant->user, ApplicantStatusChanged::class);
    Notification::assertSentOnDemand(ApplicantStatusChanged::class,
        fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === $applicant->email);
});

it('does not notify when moving a draft into checkout', function () {
    $applicant = Applicant::factory()->create();

    $applicant->transitionTo(ApplicantStatus::PendingPayment);

    Notification::assertNothingSent();
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test tests/Feature/ApplicantTransitionTest.php`
Expected: FAIL — `transitionTo` undefined.

- [ ] **Step 3: Exception**

`app/Exceptions/InvalidStatusTransition.php`:

```php
<?php

namespace App\Exceptions;

use App\Enums\ApplicantStatus;
use RuntimeException;

class InvalidStatusTransition extends RuntimeException
{
    public function __construct(ApplicantStatus $from, ApplicantStatus $to)
    {
        parent::__construct("Cannot move an application from {$from->label()} to {$to->label()}.");
    }
}
```

- [ ] **Step 4: Add `transitionTo` to `app/Models/Applicant.php`**

```php
use App\Exceptions\InvalidStatusTransition;
use App\Notifications\ApplicantStatusChanged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

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
            $current = ApplicantStatus::from(
                static::query()->whereKey($this->getKey())->lockForUpdate()->value('status')
            );

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
```

- [ ] **Step 5: Notification**

`app/Notifications/ApplicantStatusChanged.php`:

```php
<?php

namespace App\Notifications;

use App\Enums\ApplicantStatus;
use App\Models\Applicant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Storage;

class ApplicantStatusChanged extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Applicant $applicant) {}

    public function via(object $notifiable): array
    {
        return ['mail']; // WhatsApp channel added here later.
    }

    public function toMail(object $notifiable): MailMessage
    {
        $a = $this->applicant;
        $mail = (new MailMessage)
            ->subject("Visa application update: {$a->full_name}")
            ->line("The visa application for {$a->full_name} ({$a->event->name}) is now: {$a->status->label()}.");

        match ($a->status) {
            ApplicantStatus::NeedsCorrection => $mail->line('What needs fixing: '.$a->correction_note),
            ApplicantStatus::Applied => $mail->line('We have submitted the application to the Bahrain eVisa system.'
                .($a->gov_reference_no ? " Reference: {$a->gov_reference_no}." : '')),
            ApplicantStatus::VisaIssued => $mail->line('The visa is attached and can also be downloaded from your dashboard.')
                ->attachData(
                    Storage::disk(config('visa.disk'))->get($a->visa_pdf_path),
                    "visa-{$a->passport_number}.pdf",
                    ['mime' => 'application/pdf'],
                ),
            ApplicantStatus::Rejected => $mail->line('Reason: '.$a->rejection_reason),
            default => null,
        };

        return $mail->action('Open your dashboard', route('dashboard'));
    }
}
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `php artisan test tests/Feature/ApplicantTransitionTest.php`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add -A && git commit -m "feat: guarded applicant status transitions with audit log and notifications"
```

---

### Task 4: MRZ parser

**Files:**
- Create: `app/Services/MrzParser.php`
- Test: `tests/Unit/MrzParserTest.php`

**Interfaces:**
- Produces: `MrzParser::parse(string $ocrText): ?array` returning keys `full_name, passport_number, nationality, issuing_country, date_of_birth (Y-m-d), passport_expiry_date (Y-m-d)` or `null`; `MrzParser::checkDigit(string): int`.

- [ ] **Step 1: Write the failing test** (ICAO 9303 specimen passport)

`tests/Unit/MrzParserTest.php`:

```php
<?php

use App\Services\MrzParser;

const SPECIMEN = "P<UTOERIKSSON<<ANNA<MARIA<<<<<<<<<<<<<<<<<<<\nL898902C36UTO7408122F1204159ZE184226B<<<<<10";

it('computes ICAO check digits', function () {
    expect(MrzParser::checkDigit('L898902C3'))->toBe(6)
        ->and(MrzParser::checkDigit('740812'))->toBe(2)
        ->and(MrzParser::checkDigit('120415'))->toBe(9);
});

it('parses a TD3 passport MRZ', function () {
    expect((new MrzParser)->parse(SPECIMEN))->toBe([
        'full_name' => 'ANNA MARIA ERIKSSON',
        'passport_number' => 'L898902C3',
        'nationality' => 'UTO',
        'issuing_country' => 'UTO',
        'date_of_birth' => '1974-08-12',
        'passport_expiry_date' => '2012-04-15',
    ]);
});

it('finds the MRZ inside noisy OCR output with stray spaces', function () {
    $noisy = "REPUBLIC OF UTOPIA\nPassport\nP<UTOERIKSSON<<ANNA<MARIA<<<<< <<<<<<<<<<<<<<\n L898902C36UTO7408122F1204159ZE184226B<<<<<10 \n";

    expect((new MrzParser)->parse($noisy)['passport_number'])->toBe('L898902C3');
});

it('fixes O/0 confusion in numeric fields', function () {
    $ocr = "P<UTOERIKSSON<<ANNA<MARIA<<<<<<<<<<<<<<<<<<<\nL898902C36UTO74O8122F12O4159ZE184226B<<<<<10";

    expect((new MrzParser)->parse($ocr)['date_of_birth'])->toBe('1974-08-12');
});

it('maps the German D code to DEU', function () {
    $line1 = str_pad('P<D<<MUSTERMANN<<ERIKA', 44, '<');
    $number = 'C01X00T47';
    $line2 = $number.MrzParser::checkDigit($number).'D<<'.'640812'.MrzParser::checkDigit('640812').'F'
        .'270810'.MrzParser::checkDigit('270810').str_repeat('<', 14).'<'.'0';

    expect((new MrzParser)->parse("$line1\n$line2"))
        ->toMatchArray(['nationality' => 'DEU', 'issuing_country' => 'DEU']);
});

it('returns null when a check digit fails', function () {
    expect((new MrzParser)->parse(str_replace('L898902C36', 'L898902C37', SPECIMEN)))->toBeNull();
});

it('returns null for text without an MRZ', function () {
    expect((new MrzParser)->parse('just some words'))->toBeNull();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/MrzParserTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement**

`app/Services/MrzParser.php`:

```php
<?php

namespace App\Services;

/**
 * Parses an ICAO 9303 TD3 (passport) machine-readable zone out of raw OCR text.
 */
class MrzParser
{
    private const LINE_LENGTH = 44;

    public static function checkDigit(string $value): int
    {
        $weights = [7, 3, 1];
        $sum = 0;

        foreach (str_split($value) as $i => $char) {
            $v = match (true) {
                ctype_digit($char) => (int) $char,
                $char === '<' => 0,
                default => ord($char) - 55, // A=10 … Z=35
            };
            $sum += $v * $weights[$i % 3];
        }

        return $sum % 10;
    }

    /** @return array{full_name:string,passport_number:string,nationality:string,issuing_country:string,date_of_birth:?string,passport_expiry_date:?string}|null */
    public function parse(string $ocrText): ?array
    {
        $lines = $this->findLines($ocrText);
        if ($lines === null) {
            return null;
        }
        [$l1, $l2] = $lines;

        $number = substr($l2, 0, 9);
        $dob = $this->digits(substr($l2, 13, 6));
        $expiry = $this->digits(substr($l2, 21, 6));

        $valid = self::checkDigit($number) === (int) $this->digits($l2[9])
            && self::checkDigit($dob) === (int) $this->digits($l2[19])
            && self::checkDigit($expiry) === (int) $this->digits($l2[27]);

        if (! $valid) {
            return null;
        }

        [$surname, $given] = array_pad(explode('<<', substr($l1, 5), 2), 2, '');
        $name = trim(preg_replace('/\s+/', ' ', str_replace('<', ' ', $given.' '.$surname)));

        return [
            'full_name' => $name,
            'passport_number' => rtrim($number, '<'),
            'nationality' => $this->country(substr($l2, 10, 3)),
            'issuing_country' => $this->country(substr($l1, 2, 3)),
            'date_of_birth' => $this->date($dob, future: false),
            'passport_expiry_date' => $this->date($expiry, future: true),
        ];
    }

    /** @return array{0:string,1:string}|null */
    private function findLines(string $text): ?array
    {
        $candidates = [];
        foreach (preg_split('/\R/', strtoupper($text)) as $line) {
            $line = preg_replace('/\s+/', '', $line);
            if (strlen($line) >= 40 && preg_match('/^[A-Z0-9<]+$/', $line)) {
                $candidates[] = substr(str_pad($line, self::LINE_LENGTH, '<'), 0, self::LINE_LENGTH);
            }
        }

        foreach ($candidates as $i => $line) {
            if ($line[0] === 'P' && isset($candidates[$i + 1])) {
                return [$line, $candidates[$i + 1]];
            }
        }

        return null;
    }

    /** OCR often reads digits as look-alike letters in numeric-only fields. */
    private function digits(string $value): string
    {
        return strtr($value, ['O' => '0', 'Q' => '0', 'D' => '0', 'I' => '1', 'L' => '1', 'Z' => '2', 'S' => '5', 'B' => '8']);
    }

    private function country(string $code): string
    {
        $code = str_replace('<', '', $code);

        return $code === 'D' ? 'DEU' : $code;
    }

    private function date(string $yymmdd, bool $future): ?string
    {
        if (! ctype_digit($yymmdd)) {
            return null;
        }

        $yy = (int) substr($yymmdd, 0, 2);
        $century = $future || $yy <= (int) now()->format('y') ? 2000 : 1900;
        [$y, $m, $d] = [$century + $yy, (int) substr($yymmdd, 2, 2), (int) substr($yymmdd, 4, 2)];

        return checkdate($m, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $m, $d) : null;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Unit/MrzParserTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -m "feat: ICAO TD3 MRZ parser with check-digit validation"
```

---

### Task 5: Passport OCR service + image sanitizer

**Files:**
- Create: `app/Services/PassportOcr.php`, `app/Services/ImageSanitizer.php`
- Test: `tests/Feature/PassportOcrTest.php`, `tests/Unit/ImageSanitizerTest.php`

**Interfaces:**
- Consumes: `MrzParser::parse()`.
- Produces: `PassportOcr::read(string $absoluteImagePath): ?array` (same keys as `MrzParser::parse`); `ImageSanitizer::toJpeg(string $bytes, int $quality = 90): string` (throws `InvalidArgumentException` on non-image).

- [ ] **Step 1: Write the failing tests**

`tests/Feature/PassportOcrTest.php`:

```php
<?php

use App\Services\PassportOcr;
use Illuminate\Support\Facades\Process;

it('runs tesseract and parses the MRZ', function () {
    Process::fake(['*' => Process::result(
        output: "noise\nP<UTOERIKSSON<<ANNA<MARIA<<<<<<<<<<<<<<<<<<<\nL898902C36UTO7408122F1204159ZE184226B<<<<<10\n",
    )]);

    $result = app(PassportOcr::class)->read('/tmp/passport.jpg');

    expect($result['passport_number'])->toBe('L898902C3');
    Process::assertRan(fn ($process) => $process->command[0] === config('visa.tesseract_path')
        && $process->command[1] === '/tmp/passport.jpg');
});

it('returns null when tesseract fails', function () {
    Process::fake(['*' => Process::result(exitCode: 1, errorOutput: 'boom')]);

    expect(app(PassportOcr::class)->read('/tmp/passport.jpg'))->toBeNull();
});
```

`tests/Unit/ImageSanitizerTest.php`:

```php
<?php

use App\Services\ImageSanitizer;

it('re-encodes a valid image to JPEG', function () {
    $img = imagecreatetruecolor(10, 10);
    ob_start();
    imagepng($img);
    $png = ob_get_clean();

    expect(substr(ImageSanitizer::toJpeg($png), 0, 2))->toBe("\xFF\xD8");
});

it('rejects bytes that are not an image', function () {
    expect(fn () => ImageSanitizer::toJpeg('<?php echo 1;'))->toThrow(InvalidArgumentException::class);
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test tests/Feature/PassportOcrTest.php tests/Unit/ImageSanitizerTest.php`
Expected: FAIL — classes not found.

- [ ] **Step 3: Implement**

`app/Services/PassportOcr.php`:

```php
<?php

namespace App\Services;

use Illuminate\Support\Facades\Process;

class PassportOcr
{
    public function __construct(private MrzParser $parser) {}

    public function read(string $absoluteImagePath): ?array
    {
        $result = Process::timeout(30)->run([
            config('visa.tesseract_path'), $absoluteImagePath, 'stdout', '--psm', '6',
        ]);

        if ($result->failed()) {
            report(new \RuntimeException('Tesseract failed: '.$result->errorOutput()));

            return null;
        }

        return $this->parser->parse($result->output());
    }
}
```

`app/Services/ImageSanitizer.php`:

```php
<?php

namespace App\Services;

class ImageSanitizer
{
    /** Decode + re-encode so metadata and anything smuggled into the file is dropped. */
    public static function toJpeg(string $bytes, int $quality = 90): string
    {
        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            throw new \InvalidArgumentException('Not a valid image.');
        }

        ob_start();
        imagejpeg($image, null, $quality);
        imagedestroy($image);

        return ob_get_clean();
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test tests/Feature/PassportOcrTest.php tests/Unit/ImageSanitizerTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -m "feat: tesseract passport OCR and image re-encoding"
```

---

### Task 6: Applicant policy + protected file access

**Files:**
- Create: `app/Policies/ApplicantPolicy.php`, `app/Http/Controllers/ApplicantFileController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/ApplicantFileTest.php`

**Interfaces:**
- Produces: policy abilities `viewAny, view, viewDocuments, downloadVisa, update, delete, create`; route `applicant.file` = `GET /files/applicants/{applicant}/{kind}` with `kind ∈ passport|photo|visa`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/ApplicantFileTest.php`:

```php
<?php

use App\Enums\ApplicantStatus;
use App\Models\Applicant;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake(config('visa.disk'));
    Storage::disk(config('visa.disk'))->put('applicants/test-passport.jpg', 'x');
    $this->applicant = Applicant::factory()->create();
});

it('lets the owner open their passport scan', function () {
    $this->actingAs($this->applicant->user)
        ->get(route('applicant.file', [$this->applicant, 'passport']))
        ->assertRedirect();
});

it('lets processors open documents', function () {
    $this->actingAs(User::factory()->processor()->create())
        ->get(route('applicant.file', [$this->applicant, 'passport']))
        ->assertRedirect();
});

it('blocks other applicants and finance', function (string $role) {
    $user = $role === 'finance' ? User::factory()->finance()->create() : User::factory()->create();

    $this->actingAs($user)
        ->get(route('applicant.file', [$this->applicant, 'passport']))
        ->assertForbidden();
})->with(['applicant', 'finance']);

it('only serves the visa once issued', function () {
    $this->actingAs($this->applicant->user)
        ->get(route('applicant.file', [$this->applicant, 'visa']))
        ->assertForbidden();

    $this->applicant->forceFill(['status' => ApplicantStatus::VisaIssued, 'visa_pdf_path' => 'visas/v.pdf'])->save();
    Storage::disk(config('visa.disk'))->put('visas/v.pdf', 'pdf');

    $this->actingAs($this->applicant->user)
        ->get(route('applicant.file', [$this->applicant, 'visa']))
        ->assertRedirect();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/ApplicantFileTest.php`
Expected: FAIL — route not defined.

- [ ] **Step 3: Policy**

`app/Policies/ApplicantPolicy.php`:

```php
<?php

namespace App\Policies;

use App\Enums\ApplicantStatus;
use App\Enums\Role;
use App\Models\Applicant;
use App\Models\User;

class ApplicantPolicy
{
    private function isCaseworker(User $user): bool
    {
        return $user->hasRole(Role::Admin, Role::Processor);
    }

    public function viewAny(User $user): bool
    {
        return $this->isCaseworker($user);
    }

    public function view(User $user, Applicant $applicant): bool
    {
        return $applicant->user_id === $user->id || $this->isCaseworker($user);
    }

    public function viewDocuments(User $user, Applicant $applicant): bool
    {
        return $this->view($user, $applicant);
    }

    public function downloadVisa(User $user, Applicant $applicant): bool
    {
        return $this->isCaseworker($user)
            || ($applicant->user_id === $user->id && $applicant->status === ApplicantStatus::VisaIssued);
    }

    public function create(User $user): bool
    {
        return false; // staff never create applicants in Filament; the portal checks ownership itself
    }

    public function update(User $user, Applicant $applicant): bool
    {
        return $applicant->user_id === $user->id && $applicant->status->isEditableByApplicant();
    }

    public function delete(User $user, Applicant $applicant): bool
    {
        return $applicant->user_id === $user->id && $applicant->status === ApplicantStatus::Draft;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
```

- [ ] **Step 4: Controller + route**

`app/Http/Controllers/ApplicantFileController.php`:

```php
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
```

In `routes/web.php` add:

```php
use App\Http\Controllers\ApplicantFileController;

Route::middleware('auth')->group(function () {
    Route::get('/files/applicants/{applicant}/{kind}', ApplicantFileController::class)
        ->whereIn('kind', ['passport', 'photo', 'visa'])
        ->name('applicant.file');
});
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test tests/Feature/ApplicantFileTest.php`
Expected: PASS. (If `temporaryUrl` throws on the fake local disk, ensure `config/filesystems.php` disk `local` has `'serve' => true` — Laravel 12 default.)

- [ ] **Step 6: Commit**

```bash
git add -A && git commit -m "feat: applicant policy and signed file access"
```

---

### Task 7: Applicant portal — dashboard and applicant form

**Files:**
- Create: `app/Livewire/Dashboard.php`, `app/Livewire/ApplicantForm.php`
- Create: `resources/views/livewire/dashboard.blade.php`, `resources/views/livewire/applicant-form.blade.php`
- Modify: `routes/web.php` (replace kit's `dashboard` route)
- Test: `tests/Feature/ApplicantFormTest.php`, `tests/Feature/DashboardTest.php`

**Interfaces:**
- Consumes: `PassportOcr::read`, `ImageSanitizer::toJpeg`, `Applicant::transitionTo`, `ApplicantPolicy`, `Event::acceptingApplications()`.
- Produces: routes `dashboard` (`/dashboard`), `applicants.create` (`/events/{event:slug}/applicants/create`), `applicants.edit` (`/applicants/{applicant}/edit`); `Dashboard` component with public `array $selected` (used by Task 10's `pay()`).

Views here are functional Tailwind; Task 16 replaces their markup with the Claude Design output (keeping every `wire:` binding). If the Claude Design HTML (brief: `docs/design-brief.md`) is already available when this task starts, build these views from it directly and Task 16 shrinks to the auth screens, layout and invoice.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/ApplicantFormTest.php`:

```php
<?php

use App\Enums\ApplicantStatus;
use App\Livewire\ApplicantForm;
use App\Models\Applicant;
use App\Models\Event;
use App\Models\User;
use App\Services\PassportOcr;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake(config('visa.disk'));
    $this->user = User::factory()->create();
    $this->event = Event::factory()->create(['end_date' => today()->addDays(35)]);
});

function validForm(): array
{
    return [
        'full_name' => 'Anna Maria Eriksson',
        'email' => 'anna@example.com',
        'nationality' => 'SWE',
        'date_of_birth' => '1974-08-12',
        'passport_number' => 'L898902C3',
        'issuing_country' => 'SWE',
        'passport_issue_date' => today()->subYear()->toDateString(),
        'passport_expiry_date' => today()->addYears(5)->toDateString(),
        'mobile' => '+46700000000',
        'whatsapp' => '+46700000000',
    ];
}

it('prefills fields from the passport MRZ', function () {
    $this->mock(PassportOcr::class)->shouldReceive('read')->once()->andReturn([
        'full_name' => 'ANNA MARIA ERIKSSON', 'passport_number' => 'L898902C3', 'nationality' => 'SWE',
        'issuing_country' => 'SWE', 'date_of_birth' => '1974-08-12', 'passport_expiry_date' => '2031-04-15',
    ]);

    Livewire::actingAs($this->user)
        ->test(ApplicantForm::class, ['event' => $this->event])
        ->set('passportScan', UploadedFile::fake()->image('passport.jpg'))
        ->assertSet('form.passport_number', 'L898902C3')
        ->assertSet('form.full_name', 'ANNA MARIA ERIKSSON')
        ->assertSet('ocrMessage', 'Passport read. Please check every field.');
});

it('tells the user to type details when OCR fails', function () {
    $this->mock(PassportOcr::class)->shouldReceive('read')->andReturnNull();

    Livewire::actingAs($this->user)
        ->test(ApplicantForm::class, ['event' => $this->event])
        ->set('passportScan', UploadedFile::fake()->image('passport.jpg'))
        ->assertSet('ocrMessage', "Couldn't read the passport automatically. Please fill in the details.");
});

it('saves a draft applicant with sanitized files', function () {
    $this->mock(PassportOcr::class)->shouldReceive('read')->andReturnNull();

    Livewire::actingAs($this->user)
        ->test(ApplicantForm::class, ['event' => $this->event])
        ->set('form', validForm())
        ->set('passportScan', UploadedFile::fake()->image('passport.jpg'))
        ->set('photo', UploadedFile::fake()->image('photo.png'))
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('dashboard'));

    $applicant = Applicant::sole();
    expect($applicant->status)->toBe(ApplicantStatus::Draft)
        ->and($applicant->user_id)->toBe($this->user->id)
        ->and($applicant->photo_path)->toEndWith('.jpg');
    Storage::disk(config('visa.disk'))->assertExists([$applicant->passport_scan_path, $applicant->photo_path]);
});

it('rejects a passport expiring within six months of the event end', function () {
    Livewire::actingAs($this->user)
        ->test(ApplicantForm::class, ['event' => $this->event])
        ->set('form', ['passport_expiry_date' => today()->addDays(60)->toDateString()] + validForm())
        ->call('save')
        ->assertHasErrors('form.passport_expiry_date');
});

it('rejects a duplicate passport for the same event', function () {
    Applicant::factory()->for($this->event)->create(['passport_number' => 'L898902C3']);

    Livewire::actingAs($this->user)
        ->test(ApplicantForm::class, ['event' => $this->event])
        ->set('form', validForm())
        ->call('save')
        ->assertHasErrors('form.passport_number');
});

it('refuses new applicants for a closed event', function () {
    $this->event->update(['is_open' => false]);

    $this->actingAs($this->user)
        ->get(route('applicants.create', $this->event))
        ->assertForbidden();
});

it('resubmits a corrected application', function () {
    Notification::fake();
    $applicant = Applicant::factory()->for($this->user)->for($this->event)
        ->status(ApplicantStatus::NeedsCorrection)->create();

    Livewire::actingAs($this->user)
        ->test(ApplicantForm::class, ['applicant' => $applicant])
        ->set('photo', UploadedFile::fake()->image('new.jpg'))
        ->call('save')
        ->assertHasNoErrors();

    expect($applicant->fresh()->status)->toBe(ApplicantStatus::Submitted);
});

it('does not let users edit someone else’s applicant', function () {
    $applicant = Applicant::factory()->create();

    $this->actingAs($this->user)->get(route('applicants.edit', $applicant))->assertForbidden();
});
```

`tests/Feature/DashboardTest.php`:

```php
<?php

use App\Models\Applicant;
use App\Models\Event;
use App\Models\User;

it('shows open events and only my applicants', function () {
    $me = User::factory()->create();
    $event = Event::factory()->create(['name' => 'Gulf Games 2027']);
    Applicant::factory()->for($me)->for($event)->create(['full_name' => 'My Athlete']);
    Applicant::factory()->create(['full_name' => 'Someone Else']);

    $this->actingAs($me)->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Gulf Games 2027')
        ->assertSee('My Athlete')
        ->assertDontSee('Someone Else');
});

it('requires a verified email', function () {
    $this->actingAs(User::factory()->unverified()->create())
        ->get(route('dashboard'))
        ->assertRedirect(route('verification.notice'));
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test tests/Feature/ApplicantFormTest.php tests/Feature/DashboardTest.php`
Expected: FAIL — components not found.

- [ ] **Step 3: Routes** — in `routes/web.php`, delete the kit's `Route::view('dashboard', ...)` line and add:

```php
use App\Livewire\ApplicantForm;
use App\Livewire\Dashboard;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/dashboard', Dashboard::class)->name('dashboard');
    Route::get('/events/{event:slug}/applicants/create', ApplicantForm::class)->name('applicants.create');
    Route::get('/applicants/{applicant}/edit', ApplicantForm::class)->name('applicants.edit');
});
```

- [ ] **Step 4: `app/Livewire/ApplicantForm.php`**

```php
<?php

namespace App\Livewire;

use App\Enums\ApplicantStatus;
use App\Models\Applicant;
use App\Models\Event;
use App\Services\ImageSanitizer;
use App\Services\PassportOcr;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use League\ISO3166\ISO3166;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

class ApplicantForm extends Component
{
    use WithFileUploads;

    private const FIELDS = [
        'full_name', 'email', 'nationality', 'date_of_birth', 'passport_number', 'issuing_country',
        'passport_issue_date', 'passport_expiry_date', 'mobile', 'whatsapp',
    ];

    public Event $event;

    public ?Applicant $applicant = null;

    public array $form = [];

    public $passportScan = null;

    public $photo = null;

    public ?string $ocrMessage = null;

    public ?array $ocrRaw = null;

    public function mount(?Event $event = null, ?Applicant $applicant = null): void
    {
        if ($applicant?->exists) {
            Gate::authorize('update', $applicant);
            $this->applicant = $applicant;
            $this->event = $applicant->event;
            foreach (self::FIELDS as $field) {
                $value = $applicant->{$field};
                $this->form[$field] = $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : $value;
            }

            return;
        }

        abort_unless($event?->exists && $event->isAcceptingApplications(), 403, 'This event is not accepting applications.');
        $this->event = $event;
        $this->form = array_fill_keys(self::FIELDS, null);
    }

    public function updatedPassportScan(): void
    {
        $this->validateOnly('passportScan');

        if ($this->passportScan->getMimeType() === 'application/pdf') {
            $this->ocrMessage = 'PDF uploaded. Please fill in the details.';

            return;
        }

        $result = app(PassportOcr::class)->read($this->passportScan->getRealPath());

        if ($result === null) {
            $this->ocrMessage = "Couldn't read the passport automatically. Please fill in the details.";

            return;
        }

        $this->ocrRaw = $result;
        $this->form = array_merge($this->form, array_filter($result));
        $this->ocrMessage = 'Passport read. Please check every field.';
    }

    protected function rules(): array
    {
        $countries = array_column((new ISO3166)->all(), 'alpha3');
        $editing = $this->applicant !== null;

        return [
            'form.full_name' => ['required', 'string', 'max:255'],
            'form.email' => ['required', 'email', 'max:255'],
            'form.nationality' => ['required', Rule::in($countries)],
            'form.issuing_country' => ['required', Rule::in($countries)],
            'form.date_of_birth' => ['required', 'date', 'before:today'],
            'form.passport_number' => ['required', 'string', 'max:20', 'regex:/^[A-Z0-9]+$/',
                Rule::unique('applicants', 'passport_number')->where('event_id', $this->event->id)->ignore($this->applicant?->id)],
            'form.passport_issue_date' => ['required', 'date', 'before_or_equal:today'],
            'form.passport_expiry_date' => ['required', 'date', 'after:form.passport_issue_date',
                'after_or_equal:'.$this->event->minimumPassportExpiry()->toDateString()],
            'form.mobile' => ['required', 'string', 'max:20'],
            'form.whatsapp' => ['required', 'string', 'max:20'],
            'passportScan' => [$editing ? 'nullable' : 'required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            'photo' => [$editing ? 'nullable' : 'required', 'image', 'mimes:jpg,jpeg,png', 'max:5120'],
        ];
    }

    protected function messages(): array
    {
        return [
            'form.passport_expiry_date.after_or_equal' => 'The passport must be valid for at least 6 months after the event ends.',
            'form.passport_number.unique' => 'This passport is already registered for this event.',
        ];
    }

    public function save()
    {
        $this->form['passport_number'] = strtoupper(trim((string) ($this->form['passport_number'] ?? '')));
        $this->validate();

        $data = array_intersect_key($this->form, array_flip(self::FIELDS));
        $old = [];

        if ($this->passportScan) {
            $old[] = $this->applicant?->passport_scan_path;
            $data['passport_scan_path'] = $this->store($this->passportScan);
        }
        if ($this->photo) {
            $old[] = $this->applicant?->photo_path;
            $data['photo_path'] = $this->store($this->photo);
        }
        if ($this->ocrRaw) {
            $data['ocr_raw'] = $this->ocrRaw;
        }

        if ($this->applicant) {
            $this->applicant->update($data);
            if ($this->applicant->status === ApplicantStatus::NeedsCorrection) {
                $this->applicant->transitionTo(ApplicantStatus::Submitted, auth()->user(), 'Corrected by applicant');
            }
        } else {
            auth()->user()->applicants()->create($data + ['event_id' => $this->event->id]);
        }

        Storage::disk(config('visa.disk'))->delete(array_filter($old));

        return $this->redirectRoute('dashboard');
    }

    private function store(TemporaryUploadedFile $file): string
    {
        $isPdf = $file->getMimeType() === 'application/pdf';
        $path = 'applicants/'.Str::uuid().($isPdf ? '.pdf' : '.jpg');

        Storage::disk(config('visa.disk'))->put($path, $isPdf ? $file->get() : ImageSanitizer::toJpeg($file->get()));

        return $path;
    }

    public function render()
    {
        return view('livewire.applicant-form', [
            'countries' => (new ISO3166)->all(),
        ]);
    }
}
```

- [ ] **Step 5: `resources/views/livewire/applicant-form.blade.php`**

```blade
<div class="mx-auto max-w-2xl space-y-6 p-6">
    <h1 class="text-2xl font-semibold">
        {{ $applicant ? 'Edit applicant' : 'Add applicant' }} — {{ $event->name }}
    </h1>

    @if ($applicant?->correction_note && $applicant->status === \App\Enums\ApplicantStatus::NeedsCorrection)
        <div class="rounded border border-amber-300 bg-amber-50 p-4 text-amber-900">
            <strong>Correction requested:</strong> {{ $applicant->correction_note }}
        </div>
    @endif

    <form wire:submit="save" class="space-y-4">
        <div>
            <label class="block font-medium">Passport bio page (JPG, PNG or PDF, max 5 MB)</label>
            <input type="file" wire:model="passportScan" accept=".jpg,.jpeg,.png,.pdf">
            <div wire:loading wire:target="passportScan" class="text-sm text-gray-500">Reading passport…</div>
            @if ($ocrMessage) <p class="text-sm text-blue-700">{{ $ocrMessage }}</p> @endif
            @error('passportScan') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

        @foreach ([
            'full_name' => ['Full name', 'text'], 'email' => ['Email', 'email'],
            'date_of_birth' => ['Date of birth', 'date'], 'passport_number' => ['Passport number', 'text'],
            'passport_issue_date' => ['Date of issue', 'date'], 'passport_expiry_date' => ['Date of expiry', 'date'],
            'mobile' => ['Mobile', 'tel'], 'whatsapp' => ['WhatsApp', 'tel'],
        ] as $field => [$label, $type])
            <div>
                <label class="block font-medium" for="{{ $field }}">{{ $label }}</label>
                <input id="{{ $field }}" type="{{ $type }}" wire:model="form.{{ $field }}" class="w-full rounded border p-2">
                @error("form.$field") <p class="text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
        @endforeach

        @foreach (['nationality' => 'Nationality', 'issuing_country' => 'Country of issue'] as $field => $label)
            <div>
                <label class="block font-medium" for="{{ $field }}">{{ $label }}</label>
                <select id="{{ $field }}" wire:model="form.{{ $field }}" class="w-full rounded border p-2">
                    <option value="">Select…</option>
                    @foreach ($countries as $c)
                        <option value="{{ $c['alpha3'] }}">{{ $c['name'] }}</option>
                    @endforeach
                </select>
                @error("form.$field") <p class="text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
        @endforeach

        <div>
            <label class="block font-medium">Personal photo (white background, JPG or PNG)</label>
            <input type="file" wire:model="photo" accept=".jpg,.jpeg,.png">
            @error('photo') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

        <button type="submit" class="rounded bg-red-700 px-4 py-2 text-white" wire:loading.attr="disabled">
            {{ $applicant?->status === \App\Enums\ApplicantStatus::NeedsCorrection ? 'Resubmit' : 'Save' }}
        </button>
    </form>
</div>
```

- [ ] **Step 6: `app/Livewire/Dashboard.php`** (payment action added in Task 10)

```php
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
```

- [ ] **Step 7: `resources/views/livewire/dashboard.blade.php`**

```blade
@php use App\Enums\ApplicantStatus; @endphp
<div class="mx-auto max-w-5xl space-y-8 p-6">
    <section>
        <h2 class="mb-3 text-xl font-semibold">Open events</h2>
        @forelse ($events as $event)
            <div class="flex items-center justify-between border-b py-2">
                <div>
                    <div class="font-medium">{{ $event->name }}</div>
                    <div class="text-sm text-gray-600">
                        {{ $event->start_date->format('d M Y') }} – {{ $event->end_date->format('d M Y') }}
                        · Visa fee {{ $event->currency }} {{ number_format($event->visa_fee, 3) }}
                        · Apply by {{ $event->application_deadline->format('d M Y') }}
                    </div>
                </div>
                <a href="{{ route('applicants.create', $event) }}" class="rounded bg-red-700 px-3 py-1 text-white">Add applicant</a>
            </div>
        @empty
            <p class="text-gray-600">No events are open for applications right now.</p>
        @endforelse
    </section>

    <section>
        <h2 class="mb-3 text-xl font-semibold">My applicants</h2>
        @error('selected') <p class="text-red-600">{{ $message }}</p> @enderror
        @forelse ($applicantsByEvent as $eventId => $applicants)
            @php $event = $applicants->first()->event; @endphp
            <div class="mb-6">
                <h3 class="font-medium">{{ $event->name }}</h3>
                <table class="w-full text-left text-sm">
                    <thead><tr><th></th><th>Name</th><th>Passport</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                    @foreach ($applicants as $a)
                        <tr class="border-b">
                            <td>
                                @if ($a->status === ApplicantStatus::Draft)
                                    <input type="checkbox" wire:model="selected" value="{{ $a->id }}">
                                @endif
                            </td>
                            <td>{{ $a->full_name }}</td>
                            <td>{{ $a->passport_number }}</td>
                            <td>
                                {{ $a->status->label() }}
                                @if ($a->status === ApplicantStatus::NeedsCorrection)
                                    <div class="text-amber-700">{{ $a->correction_note }}</div>
                                @elseif ($a->status === ApplicantStatus::Rejected)
                                    <div class="text-red-700">{{ $a->rejection_reason }}</div>
                                @endif
                            </td>
                            <td class="space-x-2 text-right">
                                @if ($a->status->isEditableByApplicant())
                                    <a href="{{ route('applicants.edit', $a) }}" class="underline">Edit</a>
                                @endif
                                @if ($a->status === ApplicantStatus::Draft)
                                    <button wire:click="delete({{ $a->id }})" wire:confirm="Delete this applicant?" class="text-red-700 underline">Delete</button>
                                @endif
                                @if ($a->status === ApplicantStatus::VisaIssued)
                                    <a href="{{ route('applicant.file', [$a, 'visa']) }}" class="font-medium underline">Download visa</a>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
                @if ($applicants->contains('status', ApplicantStatus::Draft))
                    @includeIf('livewire.partials.pay-button', ['event' => $event])
                @endif
            </div>
        @empty
            <p class="text-gray-600">You haven't added any applicants yet.</p>
        @endforelse
    </section>

    @if ($payments->isNotEmpty())
        <section>
            <h2 class="mb-3 text-xl font-semibold">Payments</h2>
            <table class="w-full text-left text-sm">
                <thead><tr><th>Date</th><th>Event</th><th>Amount</th><th>Status</th><th>Invoice</th></tr></thead>
                <tbody>
                @foreach ($payments as $p)
                    <tr class="border-b">
                        <td>{{ $p->created_at->format('d M Y') }}</td>
                        <td>{{ $p->event->name }}</td>
                        <td>{{ $p->currency }} {{ number_format($p->amount, 3) }}</td>
                        <td>{{ $p->status->label() }}</td>
                        <td>
                            @foreach ($p->invoices as $invoice)
                                @if (Route::has('invoices.download'))
                                    <a href="{{ route('invoices.download', $invoice) }}" class="underline">{{ $invoice->number }}</a>
                                @endif
                            @endforeach
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </section>
    @endif
</div>
```

- [ ] **Step 8: Run tests to verify they pass**

Run: `php artisan test tests/Feature/ApplicantFormTest.php tests/Feature/DashboardTest.php`
Expected: PASS.

- [ ] **Step 9: Commit**

```bash
git add -A && git commit -m "feat: applicant portal dashboard and applicant form with OCR prefill"
```

---

### Task 8: Tap client

**Files:**
- Create: `app/Services/TapClient.php`
- Test: `tests/Feature/TapClientTest.php`

**Interfaces:**
- Produces: `TapClient::createCharge(Payment $payment, User $payer): array` (Tap charge JSON; `['id']`, `['transaction']['url']`), `TapClient::retrieveCharge(string $chargeId): array`, `TapClient::signature(array $payload): string`, `TapClient::isValidWebhook(array $payload, ?string $hashstring): bool`. Route names used: `tap.webhook`, `checkout.return` (defined in Task 10).

> Before relying on the webhook signature, confirm the hashstring formula on Tap's "Webhook" docs page with a sandbox charge (Task 10 Step 9). The formula below is Tap's documented one at the time of writing.

- [ ] **Step 1: Write the failing test**

`tests/Feature/TapClientTest.php`:

```php
<?php

use App\Models\Payment;
use App\Services\TapClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    // Routes are added in Task 10; register stubs so route() resolves here.
    Route::post('/webhooks/tap', fn () => null)->name('tap.webhook');
    Route::get('/checkout/{payment}/return', fn () => null)->name('checkout.return');
});

it('creates a charge with the payment amount and redirect urls', function () {
    Http::fake(['api.tap.company/v2/charges' => Http::response(['id' => 'chg_1', 'transaction' => ['url' => 'https://checkout.tap.company/x']])]);
    $payment = Payment::factory()->create(['amount' => 60, 'tap_charge_id' => null]);

    $charge = app(TapClient::class)->createCharge($payment, $payment->user);

    expect($charge['id'])->toBe('chg_1');
    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer sk_test_dummy')
        && $request['amount'] == 60
        && $request['currency'] === 'BHD'
        && $request['source']['id'] === 'src_all'
        && $request['reference']['order'] === (string) $payment->id
        && str_ends_with($request['redirect']['url'], "/checkout/{$payment->id}/return"));
});

it('retrieves a charge', function () {
    Http::fake(['api.tap.company/v2/charges/chg_1' => Http::response(['id' => 'chg_1', 'status' => 'CAPTURED'])]);

    expect(app(TapClient::class)->retrieveCharge('chg_1')['status'])->toBe('CAPTURED');
});

it('validates webhook signatures', function () {
    $tap = app(TapClient::class);
    $payload = ['id' => 'chg_1', 'amount' => 60, 'currency' => 'BHD', 'status' => 'CAPTURED',
        'reference' => ['gateway' => 'g1', 'payment' => 'p1'], 'transaction' => ['created' => '1700000000000']];

    expect($tap->isValidWebhook($payload, $tap->signature($payload)))->toBeTrue()
        ->and($tap->isValidWebhook($payload, 'forged'))->toBeFalse()
        ->and($tap->isValidWebhook($payload, null))->toBeFalse();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/TapClientTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement**

`app/Services/TapClient.php`:

```php
<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\User;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class TapClient
{
    private const THREE_DECIMAL_CURRENCIES = ['BHD', 'KWD', 'OMR', 'JOD'];

    public function createCharge(Payment $payment, User $payer): array
    {
        return $this->http()->post('/charges', [
            'amount' => (float) $payment->amount,
            'currency' => $payment->currency,
            'customer_initiated' => true,
            'threeDSecure' => true,
            'description' => "Visa fee - {$payment->event->name}",
            'reference' => ['transaction' => "pay_{$payment->id}", 'order' => (string) $payment->id],
            'customer' => ['first_name' => $payer->name, 'email' => $payer->email],
            'source' => ['id' => 'src_all'],
            'post' => ['url' => route('tap.webhook')],
            'redirect' => ['url' => route('checkout.return', $payment)],
        ])->throw()->json();
    }

    public function retrieveCharge(string $chargeId): array
    {
        return $this->http()->get("/charges/{$chargeId}")->throw()->json();
    }

    public function signature(array $payload): string
    {
        $decimals = in_array($payload['currency'] ?? '', self::THREE_DECIMAL_CURRENCIES, true) ? 3 : 2;

        $string = 'x_id'.($payload['id'] ?? '')
            .'x_amount'.number_format((float) ($payload['amount'] ?? 0), $decimals, '.', '')
            .'x_currency'.($payload['currency'] ?? '')
            .'x_gateway_reference'.($payload['reference']['gateway'] ?? '')
            .'x_payment_reference'.($payload['reference']['payment'] ?? '')
            .'x_status'.($payload['status'] ?? '')
            .'x_created'.($payload['transaction']['created'] ?? '');

        return hash_hmac('sha256', $string, (string) config('visa.tap.secret_key'));
    }

    public function isValidWebhook(array $payload, ?string $hashstring): bool
    {
        return $hashstring !== null && hash_equals($this->signature($payload), $hashstring);
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl(config('visa.tap.base_url'))
            ->withToken((string) config('visa.tap.secret_key'))
            ->acceptJson()
            ->timeout(20);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/TapClientTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -m "feat: Tap payments API client with webhook signature check"
```

---

### Task 9: Invoices and credit notes

**Files:**
- Create: `app/Services/InvoiceService.php`, `app/Jobs/GenerateInvoice.php`, `app/Notifications/PaymentConfirmed.php`
- Create: `app/Policies/InvoicePolicy.php`, `app/Http/Controllers/InvoiceDownloadController.php`
- Create: `resources/views/invoices/pdf.blade.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/InvoiceTest.php`

**Interfaces:**
- Consumes: `Payment`, `Invoice`, `config('visa.invoice.*')`.
- Produces: `InvoiceService::issue(Payment): Invoice` (idempotent; `wasRecentlyCreated` true only on first issue), `InvoiceService::creditNote(Invoice $original): Invoice`; `GenerateInvoice` job (constructor `Payment $payment`); `PaymentConfirmed` notification (constructor `Invoice $invoice`); route `invoices.download` = `GET /invoices/{invoice}`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/InvoiceTest.php`:

```php
<?php

use App\Enums\PaymentStatus;
use App\Jobs\GenerateInvoice;
use App\Models\Applicant;
use App\Models\Payment;
use App\Models\User;
use App\Notifications\PaymentConfirmed;
use App\Services\InvoiceService;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake(config('visa.disk'));
    $this->travelTo('2026-12-01 10:00:00');
});

function paidPayment(): Payment
{
    $payment = Payment::factory()->create(['status' => PaymentStatus::Paid, 'amount' => 60, 'paid_at' => now()]);
    $payment->applicants()->attach(Applicant::factory()->count(2)->create(['event_id' => $payment->event_id]));

    return $payment;
}

it('numbers invoices sequentially per year', function () {
    $service = app(InvoiceService::class);

    expect($service->issue(paidPayment())->number)->toBe('BOC-2026-00001')
        ->and($service->issue(paidPayment())->number)->toBe('BOC-2026-00002');
});

it('issues one invoice per payment and stores the PDF', function () {
    $payment = paidPayment();
    $service = app(InvoiceService::class);

    $first = $service->issue($payment);
    $again = $service->issue($payment);

    expect($again->id)->toBe($first->id)
        ->and($again->wasRecentlyCreated)->toBeFalse()
        ->and((string) $first->total)->toBe('60.000');
    Storage::disk(config('visa.disk'))->assertExists($first->pdf_path);
});

it('issues a credit note in its own series referencing the invoice', function () {
    $service = app(InvoiceService::class);
    $invoice = $service->issue(paidPayment());

    $credit = $service->creditNote($invoice);

    expect($credit->number)->toBe('BOC-CN-2026-00001')
        ->and($credit->type)->toBe('credit_note')
        ->and($credit->references_invoice_id)->toBe($invoice->id);
});

it('emails the invoice once when the job runs twice', function () {
    Notification::fake();
    $payment = paidPayment();

    GenerateInvoice::dispatchSync($payment);
    GenerateInvoice::dispatchSync($payment);

    Notification::assertSentToTimes($payment->user, PaymentConfirmed::class, 1);
});

it('lets the payer and finance download, nobody else', function () {
    $invoice = app(InvoiceService::class)->issue($payment = paidPayment());

    $this->actingAs($payment->user)->get(route('invoices.download', $invoice))->assertOk();
    $this->actingAs(User::factory()->finance()->create())->get(route('invoices.download', $invoice))->assertOk();
    $this->actingAs(User::factory()->create())->get(route('invoices.download', $invoice))->assertForbidden();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/InvoiceTest.php`
Expected: FAIL — classes not found.

- [ ] **Step 3: `app/Services/InvoiceService.php`**

```php
<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Payment;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class InvoiceService
{
    public function issue(Payment $payment): Invoice
    {
        return DB::transaction(function () use ($payment) {
            $existing = $payment->invoices()->where('type', 'invoice')->lockForUpdate()->first();
            if ($existing) {
                return $existing;
            }

            $subtotal = (float) $payment->amount;
            $vat = round($subtotal * (float) $payment->event->vat_rate / 100, 3);

            return $this->create($payment, 'invoice', config('visa.invoice.prefix'), $subtotal, $vat);
        });
    }

    public function creditNote(Invoice $original): Invoice
    {
        return DB::transaction(fn () => $original->payment->invoices()->where('type', 'credit_note')->first()
            ?? $this->create(
                $original->payment, 'credit_note', config('visa.invoice.prefix').'-CN',
                (float) $original->subtotal, (float) $original->vat_amount, $original,
            ));
    }

    private function create(Payment $payment, string $type, string $prefix, float $subtotal, float $vat, ?Invoice $references = null): Invoice
    {
        $series = $prefix.'-'.now()->year;
        DB::table('invoice_sequences')->insertOrIgnore(['series' => $series, 'last_number' => 0]);
        $next = DB::table('invoice_sequences')->where('series', $series)->lockForUpdate()->value('last_number') + 1;
        DB::table('invoice_sequences')->where('series', $series)->update(['last_number' => $next]);

        $invoice = $payment->invoices()->create([
            'number' => sprintf('%s-%05d', $series, $next),
            'type' => $type,
            'references_invoice_id' => $references?->id,
            'subtotal' => $subtotal,
            'vat_amount' => $vat,
            'total' => $subtotal + $vat,
            'issued_at' => now(),
        ]);

        $path = "invoices/{$invoice->number}.pdf";
        $invoice->load('payment.user', 'payment.event', 'payment.applicants', 'references');
        Storage::disk(config('visa.disk'))->put($path, Pdf::loadView('invoices.pdf', ['invoice' => $invoice])->output());
        $invoice->update(['pdf_path' => $path]);

        return $invoice;
    }
}
```

- [ ] **Step 4: Job, notification, policy, controller, route**

`app/Jobs/GenerateInvoice.php`:

```php
<?php

namespace App\Jobs;

use App\Models\Payment;
use App\Notifications\PaymentConfirmed;
use App\Services\InvoiceService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GenerateInvoice implements ShouldQueue
{
    use Queueable;

    public function __construct(public Payment $payment) {}

    public function handle(InvoiceService $invoices): void
    {
        $invoice = $invoices->issue($this->payment);

        if ($invoice->wasRecentlyCreated) {
            $this->payment->user->notify(new PaymentConfirmed($invoice));
        }
    }
}
```

`app/Notifications/PaymentConfirmed.php`:

```php
<?php

namespace App\Notifications;

use App\Models\Invoice;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Storage;

class PaymentConfirmed extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Invoice $invoice) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $payment = $this->invoice->payment;

        return (new MailMessage)
            ->subject("Payment received — {$payment->event->name}")
            ->line("We received your payment of {$payment->currency} ".number_format((float) $payment->amount, 3)
                ." for {$payment->applicants()->count()} visa application(s).")
            ->line('Our team will now process the applications. You will be emailed at each step.')
            ->line("Invoice {$this->invoice->number} is attached.")
            ->action('Open your dashboard', route('dashboard'))
            ->attachData(
                Storage::disk(config('visa.disk'))->get($this->invoice->pdf_path),
                "{$this->invoice->number}.pdf",
                ['mime' => 'application/pdf'],
            );
    }
}
```

`app/Policies/InvoicePolicy.php`:

```php
<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Invoice;
use App\Models\User;

class InvoicePolicy
{
    public function view(User $user, Invoice $invoice): bool
    {
        return $invoice->payment->user_id === $user->id || $user->hasRole(Role::Admin, Role::Finance);
    }
}
```

`app/Http/Controllers/InvoiceDownloadController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

class InvoiceDownloadController
{
    public function __invoke(Invoice $invoice)
    {
        Gate::authorize('view', $invoice);

        return Storage::disk(config('visa.disk'))->download($invoice->pdf_path, "{$invoice->number}.pdf");
    }
}
```

In `routes/web.php`, inside the existing `Route::middleware('auth')->group(...)`:

```php
Route::get('/invoices/{invoice}', \App\Http\Controllers\InvoiceDownloadController::class)->name('invoices.download');
```

- [ ] **Step 5: `resources/views/invoices/pdf.blade.php`**

```blade
@php($p = $invoice->payment)
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #111; }
        table { width: 100%; border-collapse: collapse; margin-top: 16px; }
        th, td { border-bottom: 1px solid #ddd; padding: 6px; text-align: left; }
        .right { text-align: right; }
        h1 { font-size: 20px; margin: 0; }
    </style>
</head>
<body>
    @if (file_exists(public_path('images/boc-logo.png')))
        <img src="{{ public_path('images/boc-logo.png') }}" height="60">
    @endif
    <h1>{{ $invoice->type === 'credit_note' ? 'Credit Note' : 'Invoice' }} {{ $invoice->number }}</h1>
    <p>
        {{ config('visa.invoice.company_name') }}<br>{{ config('visa.invoice.address') }}
    </p>
    <p>
        Issued: {{ $invoice->issued_at->format('d M Y') }}<br>
        Billed to: {{ $p->user->name }} ({{ $p->user->email }})<br>
        Event: {{ $p->event->name }}
        @if ($invoice->references) <br>Refers to invoice: {{ $invoice->references->number }} @endif
    </p>

    <table>
        <thead><tr><th>Applicant</th><th>Passport</th><th class="right">Amount ({{ $p->currency }})</th></tr></thead>
        <tbody>
        @foreach ($p->applicants as $a)
            <tr>
                <td>Visa fee — {{ $a->full_name }}</td>
                <td>{{ $a->passport_number }}</td>
                <td class="right">{{ number_format((float) $invoice->subtotal / max($p->applicants->count(), 1), 3) }}</td>
            </tr>
        @endforeach
        </tbody>
        <tfoot>
            <tr><td colspan="2" class="right">Subtotal</td><td class="right">{{ number_format((float) $invoice->subtotal, 3) }}</td></tr>
            @if ((float) $invoice->vat_amount > 0)
                <tr><td colspan="2" class="right">VAT ({{ $p->event->vat_rate }}%)</td><td class="right">{{ number_format((float) $invoice->vat_amount, 3) }}</td></tr>
            @endif
            <tr><th colspan="2" class="right">Total</th><th class="right">{{ number_format((float) $invoice->total, 3) }}</th></tr>
        </tfoot>
    </table>

    <p>
        Paid via Tap{{ $p->payment_method ? ' ('.$p->payment_method.')' : '' }} on {{ $p->paid_at?->format('d M Y H:i') }}<br>
        Tap charge ID: {{ $p->tap_charge_id }}
    </p>
</body>
</html>
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test tests/Feature/InvoiceTest.php`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add -A && git commit -m "feat: sequential PDF invoices, credit notes and payment confirmation email"
```

---

### Task 10: Checkout, webhook, return page

**Files:**
- Create: `app/Services/PaymentService.php`, `app/Policies/PaymentPolicy.php`
- Create: `app/Http/Controllers/TapWebhookController.php`, `app/Http/Controllers/CheckoutReturnController.php`
- Create: `resources/views/checkout/return.blade.php`, `resources/views/livewire/partials/pay-button.blade.php`
- Modify: `app/Livewire/Dashboard.php`, `routes/web.php`, `bootstrap/app.php`
- Test: `tests/Feature/CheckoutTest.php`

**Interfaces:**
- Consumes: `TapClient`, `GenerateInvoice`, `InvoiceService::creditNote`, `Applicant::transitionTo`.
- Produces: `PaymentService::start(User, Event, Collection<Applicant>): string` (Tap URL), `markPaid(Payment, array $charge): void`, `markFailed(Payment, array $charge): void`, `markRefunded(Payment, User $by): Invoice`, `syncFromCharge(Payment, array $charge): void`; `Dashboard::pay(int $eventId)`; routes `tap.webhook` (`POST /webhooks/tap`), `checkout.return` (`GET /checkout/{payment}/return`).

- [ ] **Step 1: Write the failing test**

`tests/Feature/CheckoutTest.php`:

```php
<?php

use App\Enums\ApplicantStatus;
use App\Enums\PaymentStatus;
use App\Jobs\GenerateInvoice;
use App\Livewire\Dashboard;
use App\Models\Applicant;
use App\Models\Event;
use App\Models\Payment;
use App\Models\User;
use App\Services\PaymentService;
use App\Services\TapClient;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->event = Event::factory()->create(['visa_fee' => 30]);
    $this->drafts = Applicant::factory()->count(2)->for($this->user)->for($this->event)->create();
});

function fakeTap(string $status = 'CAPTURED'): void
{
    Http::fake([
        'api.tap.company/v2/charges' => Http::response(['id' => 'chg_TEST1', 'transaction' => ['url' => 'https://checkout.tap.company/pay/x']]),
        'api.tap.company/v2/charges/*' => Http::response(['id' => 'chg_TEST1', 'status' => $status, 'amount' => 60, 'currency' => 'BHD', 'source' => ['payment_method' => 'VISA']]),
    ]);
}

it('starts a group checkout for the selected drafts', function () {
    fakeTap();

    Livewire::actingAs($this->user)->test(Dashboard::class)
        ->set('selected', $this->drafts->pluck('id')->all())
        ->call('pay', $this->event->id)
        ->assertRedirect('https://checkout.tap.company/pay/x');

    $payment = Payment::sole();
    expect((string) $payment->amount)->toBe('60.000')
        ->and($payment->tap_charge_id)->toBe('chg_TEST1')
        ->and($payment->applicants)->toHaveCount(2)
        ->and($this->drafts->first()->fresh()->status)->toBe(ApplicantStatus::PendingPayment);
});

it('ignores applicants that are not my drafts', function () {
    fakeTap();
    $foreign = Applicant::factory()->for($this->event)->create();

    Livewire::actingAs($this->user)->test(Dashboard::class)
        ->set('selected', [$foreign->id])
        ->call('pay', $this->event->id)
        ->assertHasErrors('selected');

    expect(Payment::count())->toBe(0);
});

it('rolls back when Tap is down', function () {
    Http::fake(['api.tap.company/*' => Http::response([], 500)]);

    Livewire::actingAs($this->user)->test(Dashboard::class)
        ->set('selected', $this->drafts->pluck('id')->all())
        ->call('pay', $this->event->id)
        ->assertHasErrors('selected');

    expect(Payment::count())->toBe(0)
        ->and($this->drafts->first()->fresh()->status)->toBe(ApplicantStatus::Draft);
});

function startedPayment($test): Payment
{
    fakeTap();
    app(PaymentService::class)->start($test->user, $test->event, $test->drafts);

    return Payment::sole();
}

it('marks the payment paid from a signed webhook, once', function () {
    Bus::fake([GenerateInvoice::class]);
    $payment = startedPayment($this);
    $payload = ['id' => 'chg_TEST1', 'amount' => 60, 'currency' => 'BHD', 'status' => 'CAPTURED',
        'reference' => ['gateway' => 'g', 'payment' => 'p'], 'transaction' => ['created' => '1']];
    $hash = app(TapClient::class)->signature($payload);

    $this->postJson(route('tap.webhook'), $payload, ['hashstring' => $hash])->assertNoContent();
    $this->postJson(route('tap.webhook'), $payload, ['hashstring' => $hash])->assertNoContent();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Paid)
        ->and($payment->fresh()->payment_method)->toBe('VISA')
        ->and($this->drafts->first()->fresh()->status)->toBe(ApplicantStatus::Submitted);
    Bus::assertDispatchedTimes(GenerateInvoice::class, 1);
});

it('rejects an unsigned webhook', function () {
    $this->postJson(route('tap.webhook'), ['id' => 'chg_TEST1'], ['hashstring' => 'nope'])->assertForbidden();
});

it('returns applicants to draft when the charge fails', function () {
    $payment = startedPayment($this);

    app(PaymentService::class)->syncFromCharge($payment, ['status' => 'DECLINED']);

    expect($payment->fresh()->status)->toBe(PaymentStatus::Failed)
        ->and($this->drafts->first()->fresh()->status)->toBe(ApplicantStatus::Draft);
});

it('syncs on the return page without trusting the browser', function () {
    Bus::fake([GenerateInvoice::class]);
    $payment = startedPayment($this);

    $this->actingAs($this->user)->get(route('checkout.return', $payment))
        ->assertOk()->assertSee('Payment received');

    expect($payment->fresh()->status)->toBe(PaymentStatus::Paid);
});

it('hides another user’s return page', function () {
    $payment = startedPayment($this);

    $this->actingAs(User::factory()->create())->get(route('checkout.return', $payment))->assertForbidden();
});

it('refunds a paid payment with a credit note', function () {
    \Illuminate\Support\Facades\Storage::fake(config('visa.disk'));
    $payment = startedPayment($this);
    app(PaymentService::class)->syncFromCharge($payment, ['status' => 'CAPTURED']);

    $credit = app(PaymentService::class)->markRefunded($payment->fresh(), User::factory()->finance()->create());

    expect($payment->fresh()->status)->toBe(PaymentStatus::Refunded)
        ->and($credit->type)->toBe('credit_note');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/CheckoutTest.php`
Expected: FAIL.

- [ ] **Step 3: `app/Services/PaymentService.php`**

```php
<?php

namespace App\Services;

use App\Enums\ApplicantStatus;
use App\Enums\PaymentStatus;
use App\Jobs\GenerateInvoice;
use App\Models\Applicant;
use App\Models\Event;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PaymentService
{
    private const FAILED_STATUSES = ['ABANDONED', 'CANCELLED', 'FAILED', 'DECLINED', 'RESTRICTED', 'VOID', 'TIMEDOUT'];

    public function __construct(private TapClient $tap, private InvoiceService $invoices) {}

    /** @param Collection<int, Applicant> $applicants  @return string Tap hosted checkout URL */
    public function start(User $user, Event $event, Collection $applicants): string
    {
        $valid = $applicants->every(fn (Applicant $a) => $a->user_id === $user->id
            && $a->event_id === $event->id && $a->status === ApplicantStatus::Draft);

        if ($applicants->isEmpty() || ! $valid || ! $event->isAcceptingApplications()) {
            throw new \InvalidArgumentException('Select draft applicants from one open event.');
        }

        return DB::transaction(function () use ($user, $event, $applicants) {
            $payment = Payment::create([
                'user_id' => $user->id,
                'event_id' => $event->id,
                'amount' => (float) $event->visa_fee * $applicants->count(),
                'currency' => $event->currency,
                'status' => PaymentStatus::Initiated,
            ]);
            $payment->applicants()->attach($applicants->pluck('id'));
            $applicants->each->transitionTo(ApplicantStatus::PendingPayment, $user);

            $charge = $this->tap->createCharge($payment, $user); // throws → whole transaction rolls back
            $payment->update(['tap_charge_id' => $charge['id']]);

            return $charge['transaction']['url'];
        });
    }

    public function syncFromCharge(Payment $payment, array $charge): void
    {
        $status = strtoupper($charge['status'] ?? '');

        if ($status === 'CAPTURED') {
            $this->markPaid($payment, $charge);
        } elseif (in_array($status, self::FAILED_STATUSES, true)) {
            $this->markFailed($payment, $charge);
        }
    }

    public function markPaid(Payment $payment, array $charge): void
    {
        $changed = DB::transaction(function () use ($payment, $charge) {
            $locked = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            if ($locked->status !== PaymentStatus::Initiated) {
                return false; // already handled — webhook/return/reconcile can all race here
            }

            $locked->update([
                'status' => PaymentStatus::Paid,
                'paid_at' => now(),
                'payment_method' => $charge['source']['payment_method'] ?? null,
                'raw_payload' => $charge,
            ]);
            $locked->applicants->each->transitionTo(ApplicantStatus::Submitted);

            return true;
        });

        if ($changed) {
            GenerateInvoice::dispatch($payment->fresh());
        }
    }

    public function markFailed(Payment $payment, array $charge): void
    {
        DB::transaction(function () use ($payment, $charge) {
            $locked = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            if ($locked->status !== PaymentStatus::Initiated) {
                return;
            }

            $locked->update(['status' => PaymentStatus::Failed, 'raw_payload' => $charge ?: null]);
            $locked->applicants
                ->where('status', ApplicantStatus::PendingPayment)
                ->each->transitionTo(ApplicantStatus::Draft);
        });
    }

    /** Refund itself is done in Tap's dashboard; this records it and issues the credit note. */
    public function markRefunded(Payment $payment, User $by): Invoice
    {
        if ($payment->status !== PaymentStatus::Paid) {
            throw new \InvalidArgumentException('Only paid payments can be refunded.');
        }

        $payment->update(['status' => PaymentStatus::Refunded, 'refunded_at' => now()]);

        return $this->invoices->creditNote($this->invoices->issue($payment));
    }
}
```

- [ ] **Step 4: `app/Policies/PaymentPolicy.php`**

```php
<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Payment;
use App\Models\User;

class PaymentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole(Role::Admin, Role::Finance);
    }

    public function view(User $user, Payment $payment): bool
    {
        return $payment->user_id === $user->id || $this->viewAny($user);
    }

    public function refund(User $user, Payment $payment): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool { return false; }

    public function update(User $user, Payment $payment): bool { return false; }

    public function delete(User $user, Payment $payment): bool { return false; }

    public function deleteAny(User $user): bool { return false; }
}
```

- [ ] **Step 5: Controllers, routes, CSRF exemption**

`app/Http/Controllers/TapWebhookController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Services\PaymentService;
use App\Services\TapClient;
use Illuminate\Http\Request;

class TapWebhookController
{
    public function __invoke(Request $request, TapClient $tap, PaymentService $payments)
    {
        abort_unless($tap->isValidWebhook($request->all(), $request->header('hashstring')), 403);

        $payment = Payment::where('tap_charge_id', $request->input('id'))->first();

        if ($payment) {
            // Trust Tap's API, not the posted body.
            $payments->syncFromCharge($payment, $tap->retrieveCharge($payment->tap_charge_id));
        }

        return response()->noContent();
    }
}
```

`app/Http/Controllers/CheckoutReturnController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Services\PaymentService;
use App\Services\TapClient;
use Illuminate\Support\Facades\Gate;

class CheckoutReturnController
{
    public function __invoke(Payment $payment, TapClient $tap, PaymentService $payments)
    {
        Gate::authorize('view', $payment);

        if ($payment->status === PaymentStatus::Initiated && $payment->tap_charge_id) {
            rescue(fn () => $payments->syncFromCharge($payment, $tap->retrieveCharge($payment->tap_charge_id)));
            $payment->refresh();
        }

        return view('checkout.return', ['payment' => $payment]);
    }
}
```

`routes/web.php` additions:

```php
Route::post('/webhooks/tap', \App\Http\Controllers\TapWebhookController::class)->name('tap.webhook');

// inside the auth group:
Route::get('/checkout/{payment}/return', \App\Http\Controllers\CheckoutReturnController::class)->name('checkout.return');
```

`bootstrap/app.php` — inside `->withMiddleware(function (Middleware $middleware) { ... })`:

```php
$middleware->validateCsrfTokens(except: ['webhooks/tap']);
```

- [ ] **Step 6: Views**

`resources/views/checkout/return.blade.php`:

```blade
<x-layouts.app :title="__('Payment')">
    <div class="mx-auto max-w-xl space-y-4 p-6 text-center">
        @switch($payment->status)
            @case(\App\Enums\PaymentStatus::Paid)
                <h1 class="text-2xl font-semibold">Payment received</h1>
                <p>Thank you. Your invoice has been emailed to you and your applications are now in our queue.</p>
                @break
            @case(\App\Enums\PaymentStatus::Failed)
                <h1 class="text-2xl font-semibold">Payment did not go through</h1>
                <p>Your applicants are back in draft. You can try again from your dashboard.</p>
                @break
            @default
                <h1 class="text-2xl font-semibold">Confirming your payment…</h1>
                <p>This usually takes a few seconds. Refresh this page or check your dashboard shortly.</p>
        @endswitch
        <a href="{{ route('dashboard') }}" class="underline">Back to dashboard</a>
    </div>
</x-layouts.app>
```

`resources/views/livewire/partials/pay-button.blade.php`:

```blade
<div class="mt-2 text-right">
    <button wire:click="pay({{ $event->id }})" wire:loading.attr="disabled" class="rounded bg-red-700 px-4 py-2 text-white">
        Pay visa fee for selected ({{ $event->currency }} {{ number_format($event->visa_fee, 3) }} each)
    </button>
</div>
```

- [ ] **Step 7: Add `pay()` to `app/Livewire/Dashboard.php`**

```php
use App\Enums\ApplicantStatus;
use App\Services\PaymentService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;

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
        } catch (\InvalidArgumentException $e) {
            $this->addError('selected', $e->getMessage());
        }

        return null;
    }
```

- [ ] **Step 8: Run tests**

Run: `php artisan test`
Expected: all PASS (TapClientTest's route stubs are now harmless duplicates — delete that `beforeEach` from `tests/Feature/TapClientTest.php` and re-run to confirm the real routes work).

- [ ] **Step 9: Manual sandbox check** (needs a Tap test secret key in `.env` and a public URL for the webhook, e.g. `ngrok http 8000`; set `APP_URL` to the ngrok URL)

1. `php artisan serve`, register, add an applicant, tick it, click Pay.
2. Pay with a Tap test card on Tap's hosted page.
3. Confirm: redirect page says "Payment received", payment row is Paid, invoice email lands in `storage/logs/laravel.log` (mail driver `log`).
4. In `storage/logs/laravel.log` confirm no 403 on `/webhooks/tap`. If there is one, compare our `signature()` input string with Tap's current webhook docs and fix the formula in `TapClient::signature`.

- [ ] **Step 10: Commit**

```bash
git add -A && git commit -m "feat: Tap group checkout, signed webhook, idempotent payment sync"
```

---

### Task 11: Scheduled jobs — reconcile payments, purge files, backups

**Files:**
- Create: `app/Console/Commands/ReconcilePayments.php`, `PurgeApplicantFiles.php`, `BackupDatabase.php`
- Modify: `routes/console.php`, `config/filesystems.php`
- Test: `tests/Feature/ScheduledCommandsTest.php`

**Interfaces:**
- Consumes: `TapClient::retrieveCharge`, `PaymentService::syncFromCharge/markFailed`.
- Produces: commands `payments:reconcile`, `files:purge`, `db:backup`; filesystem disk `backups`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/ScheduledCommandsTest.php`:

```php
<?php

use App\Enums\ApplicantStatus;
use App\Enums\PaymentStatus;
use App\Models\Applicant;
use App\Models\Event;
use App\Models\Payment;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

function pendingPayment(int $minutesAgo): Payment
{
    $payment = Payment::factory()->create(['tap_charge_id' => 'chg_R1', 'created_at' => now()->subMinutes($minutesAgo)]);
    $payment->applicants()->attach(Applicant::factory()->status(ApplicantStatus::PendingPayment)->create(['event_id' => $payment->event_id]));

    return $payment;
}

it('marks captured charges paid', function () {
    Bus::fake();
    Http::fake(['api.tap.company/v2/charges/chg_R1' => Http::response(['id' => 'chg_R1', 'status' => 'CAPTURED'])]);
    $payment = pendingPayment(10);

    $this->artisan('payments:reconcile')->assertSuccessful();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Paid);
});

it('expires abandoned checkouts after the timeout', function () {
    Http::fake(['api.tap.company/v2/charges/chg_R1' => Http::response(['id' => 'chg_R1', 'status' => 'INITIATED'])]);
    $payment = pendingPayment(31);

    $this->artisan('payments:reconcile')->assertSuccessful();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Failed)
        ->and($payment->applicants->first()->fresh()->status)->toBe(ApplicantStatus::Draft);
});

it('leaves recent pending checkouts alone', function () {
    Http::fake(['api.tap.company/v2/charges/chg_R1' => Http::response(['id' => 'chg_R1', 'status' => 'INITIATED'])]);
    $payment = pendingPayment(10);

    $this->artisan('payments:reconcile')->assertSuccessful();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Initiated);
});

it('purges passport scans and photos after the retention period', function () {
    Storage::fake(config('visa.disk'));
    $disk = Storage::disk(config('visa.disk'));
    $old = Applicant::factory()->for(Event::factory()->state(['end_date' => today()->subDays(91)]))->create();
    $recent = Applicant::factory()->for(Event::factory()->state(['end_date' => today()->subDays(10)]))->create(['passport_scan_path' => 'applicants/keep.jpg']);
    $disk->put($old->passport_scan_path, 'x');
    $disk->put('applicants/keep.jpg', 'x');

    $this->artisan('files:purge')->assertSuccessful();

    $disk->assertMissing($old->passport_scan_path);
    $disk->assertExists('applicants/keep.jpg');
    expect($old->fresh()->passport_scan_path)->toBeNull()
        ->and($old->fresh()->photo_path)->toBeNull()
        ->and($recent->fresh()->passport_scan_path)->toBe('applicants/keep.jpg');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/ScheduledCommandsTest.php`
Expected: FAIL — commands not found.

- [ ] **Step 3: Commands**

`app/Console/Commands/ReconcilePayments.php`:

```php
<?php

namespace App\Console\Commands;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Services\PaymentService;
use App\Services\TapClient;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;

class ReconcilePayments extends Command
{
    protected $signature = 'payments:reconcile';

    protected $description = 'Sync initiated Tap payments and expire abandoned checkouts';

    public function handle(TapClient $tap, PaymentService $payments): int
    {
        $timeout = now()->subMinutes(config('visa.checkout_timeout_minutes'));

        Payment::query()
            ->where('status', PaymentStatus::Initiated)
            ->where('created_at', '<=', now()->subMinutes(5))
            ->each(function (Payment $payment) use ($tap, $payments, $timeout) {
                $charge = [];
                if ($payment->tap_charge_id) {
                    try {
                        $charge = $tap->retrieveCharge($payment->tap_charge_id);
                    } catch (RequestException|ConnectionException $e) {
                        report($e);

                        return; // try again next run
                    }
                    $payments->syncFromCharge($payment, $charge);
                }

                if ($payment->fresh()->status === PaymentStatus::Initiated && $payment->created_at->lte($timeout)) {
                    $payments->markFailed($payment, $charge);
                }
            });

        return self::SUCCESS;
    }
}
```

`app/Console/Commands/PurgeApplicantFiles.php`:

```php
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
                $disk->delete(array_filter([$applicant->passport_scan_path, $applicant->photo_path]));
                $applicant->forceFill(['passport_scan_path' => null, 'photo_path' => null])->save();
            });

        return self::SUCCESS;
    }
}
```

`app/Console/Commands/BackupDatabase.php` (production-only glue; verified manually in Task 17):

```php
<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

class BackupDatabase extends Command
{
    protected $signature = 'db:backup';

    protected $description = 'Dump MySQL, gzip it, upload to the backups disk';

    public function handle(): int
    {
        $db = config('database.connections.mysql');
        $file = storage_path('app/backup-'.now()->format('Ymd-His').'.sql.gz');

        $result = Process::env(['MYSQL_PWD' => $db['password']])->timeout(600)->run(sprintf(
            'mysqldump --single-transaction --quick -h %s -u %s %s | gzip > %s',
            escapeshellarg($db['host']), escapeshellarg($db['username']), escapeshellarg($db['database']), escapeshellarg($file),
        ));

        if ($result->failed()) {
            $this->error($result->errorOutput());

            return self::FAILURE;
        }

        Storage::disk('backups')->putFileAs('mysql', $file, basename($file));
        unlink($file);

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: `backups` disk** — in `config/filesystems.php` `disks`:

```php
'backups' => [
    'driver' => 's3',
    'key' => env('AWS_ACCESS_KEY_ID'),
    'secret' => env('AWS_SECRET_ACCESS_KEY'),
    'region' => env('AWS_DEFAULT_REGION', 'me-south-1'),
    'bucket' => env('BACKUP_BUCKET'),
    'throw' => true,
],
```

- [ ] **Step 5: Schedule** — append to `routes/console.php`:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('payments:reconcile')->everyTenMinutes()->withoutOverlapping();
Schedule::command('files:purge')->dailyAt('02:00');
Schedule::command('db:backup')->dailyAt('01:00')->environments(['production']);
```

- [ ] **Step 6: Run tests**

Run: `php artisan test tests/Feature/ScheduledCommandsTest.php`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add -A && git commit -m "feat: payment reconciliation, file retention purge, nightly DB backup"
```

---

### Task 12: Filament panel — access, 2FA, IP allowlist, branding

**Files:**
- Create: `app/Providers/Filament/AdminPanelProvider.php` (generated), `app/Http/Middleware/RestrictAdminIp.php`, `app/Console/Commands/MakeAdmin.php`
- Create: migration `..._add_app_authentication_to_users_table.php`
- Modify: `app/Models/User.php`, `app/Enums/ApplicantStatus.php`, `app/Enums/PaymentStatus.php`
- Test: `tests/Feature/AdminPanelAccessTest.php`

**Interfaces:**
- Produces: panel id `admin` at `/admin`; `User::canAccessPanel()`; enums implement Filament `HasLabel` + `HasColor`; command `visa:make-admin {email}`.

- [ ] **Step 1: Install Filament + export/notification tables**

```powershell
composer require filament/filament:"^4.0" -W
php artisan filament:install --panels
php artisan make:notifications-table
php artisan vendor:publish --tag=filament-actions-migrations
```

(Use `^5.0` if Task 1 Step 2 found Livewire 4. Answer `admin` for panel ID. Skip `make:queue-batches-table` — Laravel 12's jobs migration already creates `job_batches`.)

- [ ] **Step 2: Write the failing test**

`tests/Feature/AdminPanelAccessTest.php`:

```php
<?php

use App\Models\User;

it('blocks applicants from the staff panel', function () {
    $this->actingAs(User::factory()->create())->get('/admin')->assertForbidden();
});

it('blocks deactivated staff', function () {
    $this->actingAs(User::factory()->processor()->create(['is_active' => false]))->get('/admin')->assertForbidden();
});

it('lets active staff in (to 2FA setup or dashboard)', function (string $role) {
    $user = User::factory()->{$role}()->create();

    expect($this->actingAs($user)->get('/admin')->status())->not->toBe(403);
})->with(['admin', 'processor', 'finance']);

it('enforces the admin IP allowlist when configured', function () {
    config(['visa.admin_ip_allowlist' => ['10.0.0.0/8']]);
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->get('/admin')->assertForbidden(); // tests run from 127.0.0.1
    expect($this->actingAs($admin)->withServerVariables(['REMOTE_ADDR' => '10.1.2.3'])->get('/admin')->status())->not->toBe(403);
});

it('promotes a user to admin from the CLI', function () {
    $user = User::factory()->create(['email' => 'ops@boc.bh']);

    $this->artisan('visa:make-admin', ['email' => 'ops@boc.bh'])->assertSuccessful();

    expect($user->fresh()->role)->toBe(\App\Enums\Role::Admin);
});
```

- [ ] **Step 3: Run test to verify it fails**

Run: `php artisan test tests/Feature/AdminPanelAccessTest.php`
Expected: FAIL (applicants currently allowed in non-production / command missing).

- [ ] **Step 4: 2FA columns migration** (`php artisan make:migration add_app_authentication_to_users_table`)

```php
public function up(): void
{
    Schema::table('users', function (Blueprint $table) {
        $table->text('app_authentication_secret')->nullable();
        $table->text('app_authentication_recovery_codes')->nullable();
    });
}

public function down(): void
{
    Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['app_authentication_secret', 'app_authentication_recovery_codes']));
}
```

- [ ] **Step 5: `User` model** — implement panel access and 2FA:

```php
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthentication;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthenticationRecovery;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;

class User extends Authenticatable implements MustVerifyEmail, FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery
{
    use InteractsWithAppAuthentication, InteractsWithAppAuthenticationRecovery;

    protected $hidden = ['password', 'remember_token', 'app_authentication_secret', 'app_authentication_recovery_codes'];

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_active && $this->role !== Role::Applicant;
    }
```

(If the trait/interface namespaces differ in the installed version, check `vendor/filament/filament/src/Auth/MultiFactor/App` and adjust the `use` lines.)

- [ ] **Step 6: `app/Http/Middleware/RestrictAdminIp.php`**

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;

class RestrictAdminIp
{
    public function handle(Request $request, Closure $next)
    {
        $allowed = config('visa.admin_ip_allowlist');

        abort_if($allowed && ! IpUtils::checkIp((string) $request->ip(), $allowed), 403);

        return $next($request);
    }
}
```

- [ ] **Step 7: Configure `app/Providers/Filament/AdminPanelProvider.php`** — in `panel()`, add/adjust:

```php
use App\Http\Middleware\RestrictAdminIp;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Support\Colors\Color;

        ->brandName('BOC Visa Portal')
        ->brandLogo(fn () => file_exists(public_path('images/boc-logo.png')) ? asset('images/boc-logo.png') : null)
        ->colors(['primary' => Color::Red])
        ->multiFactorAuthentication([
            AppAuthentication::make()->recoverable(),
        ], isRequired: true)
        ->databaseNotifications()
```

and append `RestrictAdminIp::class` as the **first** entry of the existing `->middleware([...])` array.

- [ ] **Step 8: Enum labels/colours for Filament badges**

`app/Enums/ApplicantStatus.php` — add `implements \Filament\Support\Contracts\HasLabel, \Filament\Support\Contracts\HasColor` and:

```php
    public function getLabel(): string
    {
        return $this->label();
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Draft, self::PendingPayment => 'gray',
            self::Submitted => 'info',
            self::InReview, self::Applied => 'primary',
            self::NeedsCorrection => 'warning',
            self::VisaIssued => 'success',
            self::Rejected => 'danger',
        };
    }
```

`app/Enums/PaymentStatus.php` — same interfaces and:

```php
    public function getLabel(): string
    {
        return $this->label();
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Initiated => 'gray',
            self::Paid => 'success',
            self::Failed => 'danger',
            self::Refunded => 'warning',
        };
    }
```

- [ ] **Step 9: `app/Console/Commands/MakeAdmin.php`**

```php
<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Console\Command;

class MakeAdmin extends Command
{
    protected $signature = 'visa:make-admin {email}';

    protected $description = 'Give an existing user the admin role (create them first with make:filament-user)';

    public function handle(): int
    {
        $user = User::where('email', $this->argument('email'))->first();
        if (! $user) {
            $this->error('No user with that email.');

            return self::FAILURE;
        }

        $user->forceFill(['role' => Role::Admin, 'is_active' => true, 'email_verified_at' => $user->email_verified_at ?? now()])->save();
        $this->info("{$user->email} is now an admin.");

        return self::SUCCESS;
    }
}
```

- [ ] **Step 10: Run tests**

Run: `php artisan migrate:fresh && php artisan test`
Expected: all PASS.

- [ ] **Step 11: Commit**

```bash
git add -A && git commit -m "feat: Filament staff panel with role access, required 2FA, IP allowlist"
```

---

### Task 13: Filament — Events and Staff users (admin only)

**Files:**
- Create: `app/Policies/EventPolicy.php`, `app/Policies/UserPolicy.php`
- Create: `app/Filament/Resources/Events/EventResource.php`, `.../Events/Pages/{ListEvents,CreateEvent,EditEvent}.php`
- Create: `app/Filament/Resources/Users/UserResource.php`, `.../Users/Pages/{ListUsers,CreateUser,EditUser}.php`
- Test: `tests/Feature/Filament/EventAndUserResourceTest.php`

**Interfaces:**
- Consumes: `Role`, `Event`, `User`.
- Produces: Event CRUD and staff-user management, admin only.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Filament/EventAndUserResourceTest.php`:

```php
<?php

use App\Enums\Role;
use App\Filament\Resources\Events\Pages\CreateEvent;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\Event;
use App\Models\User;
use Livewire\Livewire;

it('lets admins create an event', function () {
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test(CreateEvent::class)
        ->fillForm([
            'name' => 'Gulf Games 2027', 'slug' => 'gulf-games-2027',
            'start_date' => '2027-03-01', 'end_date' => '2027-03-10', 'application_deadline' => '2027-02-01',
            'visa_fee' => 30, 'vat_rate' => 0, 'is_open' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Event::where('slug', 'gulf-games-2027')->exists())->toBeTrue();
});

it('keeps events and staff management admin-only', function (string $role) {
    $user = User::factory()->{$role}()->create();

    expect($user->can('viewAny', Event::class))->toBeFalse()
        ->and($user->can('viewAny', User::class))->toBeFalse();
})->with(['processor', 'finance']);

it('lists only staff accounts and creates a processor', function () {
    $this->actingAs(User::factory()->admin()->create());
    $applicant = User::factory()->create();

    Livewire::test(ListUsers::class)->assertCanNotSeeTableRecords([$applicant]);

    Livewire::test(CreateUser::class)
        ->fillForm(['name' => 'Pat Processor', 'email' => 'pat@boc.bh', 'role' => Role::Processor->value, 'password' => 'a-Strong-pass-123', 'is_active' => true])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(User::where('email', 'pat@boc.bh')->first()->role)->toBe(Role::Processor);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Filament/EventAndUserResourceTest.php`
Expected: FAIL.

- [ ] **Step 3: Policies**

`app/Policies/EventPolicy.php`:

```php
<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Event;
use App\Models\User;

class EventPolicy
{
    public function viewAny(User $user): bool { return $user->hasRole(Role::Admin); }

    public function view(User $user, Event $event): bool { return $user->hasRole(Role::Admin); }

    public function create(User $user): bool { return $user->hasRole(Role::Admin); }

    public function update(User $user, Event $event): bool { return $user->hasRole(Role::Admin); }

    public function delete(User $user, Event $event): bool { return false; } // close events instead

    public function deleteAny(User $user): bool { return false; }
}
```

`app/Policies/UserPolicy.php`:

```php
<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool { return $user->hasRole(Role::Admin); }

    public function view(User $user, User $model): bool { return $user->hasRole(Role::Admin); }

    public function create(User $user): bool { return $user->hasRole(Role::Admin); }

    public function update(User $user, User $model): bool { return $user->hasRole(Role::Admin); }

    public function delete(User $user, User $model): bool { return false; } // deactivate instead

    public function deleteAny(User $user): bool { return false; }
}
```

- [ ] **Step 4: `app/Filament/Resources/Events/EventResource.php`**

```php
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
            TextInput::make('vat_rate')->label('VAT rate')->numeric()->minValue(0)->maxValue(100)->default(0)->suffix('%'),
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
```

Pages:

```php
<?php // app/Filament/Resources/Events/Pages/ListEvents.php

namespace App\Filament\Resources\Events\Pages;

use App\Filament\Resources\Events\EventResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListEvents extends ListRecords
{
    protected static string $resource = EventResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
```

```php
<?php // app/Filament/Resources/Events/Pages/CreateEvent.php

namespace App\Filament\Resources\Events\Pages;

use App\Filament\Resources\Events\EventResource;
use Filament\Resources\Pages\CreateRecord;

class CreateEvent extends CreateRecord
{
    protected static string $resource = EventResource::class;
}
```

```php
<?php // app/Filament/Resources/Events/Pages/EditEvent.php

namespace App\Filament\Resources\Events\Pages;

use App\Filament\Resources\Events\EventResource;
use Filament\Resources\Pages\EditRecord;

class EditEvent extends EditRecord
{
    protected static string $resource = EventResource::class;
}
```

- [ ] **Step 5: `app/Filament/Resources/Users/UserResource.php`**

```php
<?php

namespace App\Filament\Resources\Users;

use App\Enums\Role;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\User;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rules\Password;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationLabel = 'Staff';

    protected static ?string $modelLabel = 'staff user';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('role', '!=', Role::Applicant->value);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('email')->email()->required()->unique(ignoreRecord: true),
            Select::make('role')->required()->options([
                Role::Admin->value => 'Admin',
                Role::Processor->value => 'Processor',
                Role::Finance->value => 'Finance',
            ]),
            TextInput::make('password')->password()->revealable()
                ->rule(Password::min(12)->mixedCase()->numbers())
                ->required(fn (string $operation) => $operation === 'create')
                ->dehydrated(fn (?string $state) => filled($state)),
            Toggle::make('is_active')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('email')->searchable(),
                TextColumn::make('role')->formatStateUsing(fn (Role $state) => $state->label())->badge(),
                IconColumn::make('is_active')->boolean(),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }
}
```

Pages `ListUsers` (with `CreateAction` header action), `CreateUser`, `EditUser` — identical shape to the Event pages with `UserResource`. `CreateUser` also marks staff email as verified:

```php
<?php // app/Filament/Resources/Users/Pages/CreateUser.php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use Filament\Resources\Pages\CreateRecord;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return $data + ['email_verified_at' => now()];
    }
}
```

```php
<?php // app/Filament/Resources/Users/Pages/ListUsers.php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
```

```php
<?php // app/Filament/Resources/Users/Pages/EditUser.php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;
}
```

`email_verified_at` is not in `User::$fillable`; add it there (staff only reach it via this admin-only page).

- [ ] **Step 6: Run tests**

Run: `php artisan test tests/Feature/Filament/EventAndUserResourceTest.php`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add -A && git commit -m "feat: admin event and staff user management"
```

---

### Task 14: Filament — Applicants queue and processor actions

**Files:**
- Create: `app/Filament/Resources/Applicants/ApplicantResource.php`, `.../Applicants/Pages/{ListApplicants,ViewApplicant}.php`
- Create: `app/Filament/Exports/ApplicantExporter.php`
- Test: `tests/Feature/Filament/ApplicantResourceTest.php`

**Interfaces:**
- Consumes: `Applicant::transitionTo`, `ApplicantPolicy`, route `applicant.file`.
- Produces: processor queue + view page with header actions `claim, requestCorrection, markApplied, issueVisa, reject, override`; bulk action `downloadDocuments`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Filament/ApplicantResourceTest.php`:

```php
<?php

use App\Enums\ApplicantStatus;
use App\Filament\Resources\Applicants\Pages\ListApplicants;
use App\Filament\Resources\Applicants\Pages\ViewApplicant;
use App\Models\Applicant;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

beforeEach(function () {
    Notification::fake();
    $this->processor = User::factory()->processor()->create();
    $this->actingAs($this->processor);
});

it('shows paid applications and hides drafts', function () {
    $submitted = Applicant::factory()->status(ApplicantStatus::Submitted)->create();
    $draft = Applicant::factory()->create();

    Livewire::test(ListApplicants::class)
        ->assertCanSeeTableRecords([$submitted])
        ->assertCanNotSeeTableRecords([$draft]);
});

it('walks an application through claim, correction, applied and rejection', function () {
    $a = Applicant::factory()->status(ApplicantStatus::Submitted)->create();
    $page = fn () => Livewire::test(ViewApplicant::class, ['record' => $a->getRouteKey()]);

    $page()->callAction('claim')->assertHasNoActionErrors();
    expect($a->fresh())->status->toBe(ApplicantStatus::InReview)->assigned_processor_id->toBe($this->processor->id);

    $page()->callAction('requestCorrection', data: ['note' => 'Photo background must be white'])->assertHasNoActionErrors();
    expect($a->fresh())->status->toBe(ApplicantStatus::NeedsCorrection)->correction_note->toBe('Photo background must be white');

    $a->fresh()->transitionTo(ApplicantStatus::Submitted);
    $page()->callAction('claim');
    $page()->callAction('markApplied', data: ['gov_reference_no' => 'EV-123'])->assertHasNoActionErrors();
    expect($a->fresh())->status->toBe(ApplicantStatus::Applied)->gov_reference_no->toBe('EV-123');

    $page()->callAction('reject', data: ['reason' => 'Refused by immigration'])->assertHasNoActionErrors();
    expect($a->fresh())->status->toBe(ApplicantStatus::Rejected);
});

it('lets only admins override a status', function () {
    $a = Applicant::factory()->status(ApplicantStatus::Rejected)->create();

    Livewire::test(ViewApplicant::class, ['record' => $a->getRouteKey()])->assertActionHidden('override');

    $this->actingAs(User::factory()->admin()->create());
    Livewire::test(ViewApplicant::class, ['record' => $a->getRouteKey()])
        ->callAction('override', data: ['status' => ApplicantStatus::InReview->value, 'note' => 'Rejected by mistake'])
        ->assertHasNoActionErrors();

    expect($a->fresh()->status)->toBe(ApplicantStatus::InReview);
});

it('keeps finance out of applicant records', function () {
    expect(User::factory()->finance()->create()->can('viewAny', Applicant::class))->toBeFalse();
});
```

(The `issueVisa` file-upload action is covered at the model level by Task 3 and manually in Step 6.)

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Filament/ApplicantResourceTest.php`
Expected: FAIL.

- [ ] **Step 3: `app/Filament/Resources/Applicants/ApplicantResource.php`**

```php
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
```

- [ ] **Step 4: Pages**

```php
<?php // app/Filament/Resources/Applicants/Pages/ListApplicants.php

namespace App\Filament\Resources\Applicants\Pages;

use App\Filament\Resources\Applicants\ApplicantResource;
use Filament\Resources\Pages\ListRecords;

class ListApplicants extends ListRecords
{
    protected static string $resource = ApplicantResource::class;
}
```

```php
<?php // app/Filament/Resources/Applicants/Pages/ViewApplicant.php

namespace App\Filament\Resources\Applicants\Pages;

use App\Enums\ApplicantStatus;
use App\Enums\Role;
use App\Filament\Resources\Applicants\ApplicantResource;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ViewRecord;

class ViewApplicant extends ViewRecord
{
    protected static string $resource = ApplicantResource::class;

    private function is(ApplicantStatus ...$statuses): bool
    {
        return in_array($this->record->status, $statuses, true);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('claim')
                ->label('Claim')
                ->visible(fn () => $this->is(ApplicantStatus::Submitted))
                ->action(fn () => $this->record->transitionTo(ApplicantStatus::InReview, auth()->user(),
                    attributes: ['assigned_processor_id' => auth()->id()])),

            Action::make('requestCorrection')
                ->label('Request correction')->color('warning')
                ->visible(fn () => $this->is(ApplicantStatus::InReview))
                ->schema([Textarea::make('note')->label('What must the applicant fix?')->required()->maxLength(1000)])
                ->action(fn (array $data) => $this->record->transitionTo(ApplicantStatus::NeedsCorrection, auth()->user(),
                    $data['note'], ['correction_note' => $data['note']])),

            Action::make('markApplied')
                ->label('Mark applied')
                ->visible(fn () => $this->is(ApplicantStatus::InReview))
                ->schema([TextInput::make('gov_reference_no')->label('Government reference number')->required()->maxLength(100)])
                ->action(fn (array $data) => $this->record->transitionTo(ApplicantStatus::Applied, auth()->user(),
                    attributes: ['gov_reference_no' => $data['gov_reference_no']])),

            Action::make('issueVisa')
                ->label('Issue visa')->color('success')
                ->visible(fn () => $this->is(ApplicantStatus::Applied))
                ->schema([FileUpload::make('visa_pdf')->label('Visa PDF')->required()
                    ->disk(config('visa.disk'))->directory('visas')->visibility('private')
                    ->acceptedFileTypes(['application/pdf'])->maxSize(5120)])
                ->action(fn (array $data) => $this->record->transitionTo(ApplicantStatus::VisaIssued, auth()->user(),
                    attributes: ['visa_pdf_path' => $data['visa_pdf']])),

            Action::make('reject')
                ->color('danger')->requiresConfirmation()
                ->visible(fn () => $this->is(ApplicantStatus::InReview, ApplicantStatus::Applied))
                ->schema([Textarea::make('reason')->required()->maxLength(1000)])
                ->action(fn (array $data) => $this->record->transitionTo(ApplicantStatus::Rejected, auth()->user(),
                    $data['reason'], ['rejection_reason' => $data['reason']])),

            Action::make('override')
                ->label('Override status')->color('gray')
                ->visible(fn () => auth()->user()->hasRole(Role::Admin))
                ->schema([
                    Select::make('status')->options(ApplicantStatus::class)->required(),
                    Textarea::make('note')->label('Reason for override')->required(),
                ])
                ->action(function (array $data) {
                    $status = $data['status'] instanceof ApplicantStatus ? $data['status'] : ApplicantStatus::from($data['status']);
                    $this->record->transitionTo($status, auth()->user(), $data['note'], override: true);
                }),
        ];
    }
}
```

- [ ] **Step 5: `app/Filament/Exports/ApplicantExporter.php`**

```php
<?php

namespace App\Filament\Exports;

use App\Models\Applicant;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;

class ApplicantExporter extends Exporter
{
    protected static ?string $model = Applicant::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('id'),
            ExportColumn::make('event.name')->label('Event'),
            ExportColumn::make('full_name'),
            ExportColumn::make('email'),
            ExportColumn::make('nationality'),
            ExportColumn::make('date_of_birth'),
            ExportColumn::make('passport_number'),
            ExportColumn::make('issuing_country'),
            ExportColumn::make('passport_issue_date'),
            ExportColumn::make('passport_expiry_date'),
            ExportColumn::make('mobile'),
            ExportColumn::make('whatsapp'),
            ExportColumn::make('status')->formatStateUsing(fn ($state) => $state->label()),
            ExportColumn::make('gov_reference_no'),
            ExportColumn::make('submitted_at'),
            ExportColumn::make('issued_at'),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        return 'Applicant export finished: '.number_format($export->successful_rows).' rows.';
    }
}
```

- [ ] **Step 6: Run tests + manual check**

Run: `php artisan test tests/Feature/Filament/ApplicantResourceTest.php`
Expected: PASS.

Manual: `php artisan serve` + `php artisan queue:work`; as processor take one application from Submitted → Visa Issued uploading a PDF; confirm the applicant's dashboard shows "Download visa" and the visa email (in `storage/logs/laravel.log`) has the PDF attached. Try "Download passports & photos" on two rows.

- [ ] **Step 7: Commit**

```bash
git add -A && git commit -m "feat: processor queue, application actions, bulk document download, export"
```

---

### Task 15: Filament — Payments for Finance

**Files:**
- Create: `app/Filament/Resources/Payments/PaymentResource.php`, `.../Payments/Pages/{ListPayments,ViewPayment}.php`
- Create: `app/Filament/Exports/PaymentExporter.php`, `app/Filament/Widgets/PaymentStats.php`
- Test: `tests/Feature/Filament/PaymentResourceTest.php`

**Interfaces:**
- Consumes: `PaymentPolicy`, `PaymentService::markRefunded`, `InvoiceService`, `PaymentConfirmed`, route `invoices.download`.
- Produces: finance payments list/detail, actions `resendInvoice`, `markRefunded`; dashboard stats widget.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Filament/PaymentResourceTest.php`:

```php
<?php

use App\Enums\PaymentStatus;
use App\Filament\Resources\Payments\Pages\ListPayments;
use App\Filament\Resources\Payments\Pages\ViewPayment;
use App\Models\Payment;
use App\Models\User;
use App\Notifications\PaymentConfirmed;
use App\Services\InvoiceService;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake(config('visa.disk'));
    $this->actingAs(User::factory()->finance()->create());
    $this->paid = Payment::factory()->create(['status' => PaymentStatus::Paid, 'paid_at' => now()]);
});

it('lists every transaction for finance', function () {
    $failed = Payment::factory()->create(['status' => PaymentStatus::Failed]);

    Livewire::test(ListPayments::class)->assertCanSeeTableRecords([$this->paid, $failed]);
});

it('resends the invoice', function () {
    Notification::fake();
    app(InvoiceService::class)->issue($this->paid);

    Livewire::test(ViewPayment::class, ['record' => $this->paid->getRouteKey()])->callAction('resendInvoice');

    Notification::assertSentTo($this->paid->user, PaymentConfirmed::class);
});

it('marks a paid payment refunded with a credit note', function () {
    Livewire::test(ViewPayment::class, ['record' => $this->paid->getRouteKey()])->callAction('markRefunded');

    expect($this->paid->fresh()->status)->toBe(PaymentStatus::Refunded)
        ->and($this->paid->invoices()->where('type', 'credit_note')->exists())->toBeTrue();
});

it('hides payments from processors', function () {
    expect(User::factory()->processor()->create()->can('viewAny', Payment::class))->toBeFalse();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Filament/PaymentResourceTest.php`
Expected: FAIL.

- [ ] **Step 3: `app/Filament/Resources/Payments/PaymentResource.php`**

```php
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
```

- [ ] **Step 4: Pages**

```php
<?php // app/Filament/Resources/Payments/Pages/ListPayments.php

namespace App\Filament\Resources\Payments\Pages;

use App\Filament\Resources\Payments\PaymentResource;
use Filament\Resources\Pages\ListRecords;

class ListPayments extends ListRecords
{
    protected static string $resource = PaymentResource::class;
}
```

```php
<?php // app/Filament/Resources/Payments/Pages/ViewPayment.php

namespace App\Filament\Resources\Payments\Pages;

use App\Enums\PaymentStatus;
use App\Filament\Resources\Payments\PaymentResource;
use App\Notifications\PaymentConfirmed;
use App\Services\PaymentService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

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
                    $credit = $payments->markRefunded($this->record, auth()->user());
                    $this->record->refresh();
                    Notification::make()->title("Credit note {$credit->number} issued")->success()->send();
                }),
        ];
    }
}
```

- [ ] **Step 5: Exporter + widget**

`app/Filament/Exports/PaymentExporter.php`:

```php
<?php

namespace App\Filament\Exports;

use App\Models\Payment;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;

class PaymentExporter extends Exporter
{
    protected static ?string $model = Payment::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('id'),
            ExportColumn::make('created_at')->label('Date'),
            ExportColumn::make('user.name')->label('Payer'),
            ExportColumn::make('user.email')->label('Payer email'),
            ExportColumn::make('event.name')->label('Event'),
            ExportColumn::make('applicants_count')->counts('applicants')->label('Applicants'),
            ExportColumn::make('amount'),
            ExportColumn::make('currency'),
            ExportColumn::make('status')->formatStateUsing(fn ($state) => $state->label()),
            ExportColumn::make('payment_method'),
            ExportColumn::make('tap_charge_id'),
            ExportColumn::make('paid_at'),
            ExportColumn::make('refunded_at'),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        return 'Payment export finished: '.number_format($export->successful_rows).' rows.';
    }
}
```

`app/Filament/Widgets/PaymentStats.php`:

```php
<?php

namespace App\Filament\Widgets;

use App\Enums\PaymentStatus;
use App\Enums\Role;
use App\Models\Payment;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class PaymentStats extends StatsOverviewWidget
{
    public static function canView(): bool
    {
        return auth()->user()?->hasRole(Role::Admin, Role::Finance) ?? false;
    }

    protected function getStats(): array
    {
        $count = fn (PaymentStatus $s) => Payment::where('status', $s->value)->count();

        return [
            Stat::make('Revenue (paid)', 'BHD '.number_format((float) Payment::where('status', PaymentStatus::Paid->value)->sum('amount'), 3)),
            Stat::make('Paid', $count(PaymentStatus::Paid)),
            Stat::make('Failed', $count(PaymentStatus::Failed)),
            Stat::make('Refunded', $count(PaymentStatus::Refunded)),
        ];
    }
}
```

Per-event revenue: Finance uses the Payments table grouped by **Event** with the "Paid total" summary.

- [ ] **Step 6: Run full suite**

Run: `php artisan test`
Expected: all PASS.

- [ ] **Step 7: Commit**

```bash
git add -A && git commit -m "feat: finance payments view, refunds with credit notes, exports and stats"
```

---

### Task 16: Apply Claude Design UI to the applicant portal

Non-TDD styling task. The existing feature tests are the safety net: they assert behaviour, not markup.

**Files:**
- Modify: `resources/views/livewire/dashboard.blade.php`, `resources/views/livewire/applicant-form.blade.php`, `resources/views/livewire/partials/pay-button.blade.php`, `resources/views/checkout/return.blade.php`, the starter kit's auth views (login, register, verify email, forgot/reset password), `resources/views/components/layouts/app.blade.php`, `resources/views/invoices/pdf.blade.php`
- Create: `public/images/boc-logo.png`

- [ ] **Step 1: Design brief for Claude Design** — screens: Login, Register, Verify email, Forgot/Reset password, Dashboard (open events, my applicants grouped by event with status badges + checkbox + "Pay for selected", payments with invoice links), Add/Edit applicant (passport upload with "Reading passport…" state and OCR result banner, form fields, photo upload, correction-note banner), Payment return (received / failed / confirming), Invoice PDF. Brand: Bahrain Olympic Committee, red/white. Mobile-first (most applicants use phones). Output: Tailwind HTML.
- [ ] **Step 2: Port each screen into its Blade view**, keeping every `wire:model`, `wire:click`, `wire:submit`, `wire:loading`, `@error`, `route(...)` and the text strings asserted in tests (`Payment received`, event and applicant names).
- [ ] **Step 3: Add the BOC logo** at `public/images/boc-logo.png` (used by the layout, invoice PDF and Filament brand).
- [ ] **Step 4: Verify**

Run: `npm run build; php artisan test`
Expected: all PASS. Then click through every screen at 375 px and desktop width in the browser.

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -m "style: applicant portal UI from Claude Design"
```

---

### Task 17: Deploy to AWS Bahrain (me-south-1)

**Files:**
- Create: `deploy/nginx.conf`, `deploy/supervisor.conf`, `deploy/.env.production.example`, `deploy/README.md`

- [ ] **Step 1: `deploy/nginx.conf`**

```nginx
server {
    listen 80;
    server_name visa.example.bh;
    root /var/www/visa/public;
    index index.php;
    client_max_body_size 12M;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
    }

    location ~ /\.(?!well-known) { deny all; }
}
```

- [ ] **Step 2: `deploy/supervisor.conf`**

```ini
[program:visa-queue]
command=php /var/www/visa/artisan queue:work redis --sleep=3 --tries=3 --max-time=3600
user=www-data
numprocs=2
process_name=%(program_name)s_%(process_num)02d
autostart=true
autorestart=true
stopwaitsecs=3600
redirect_stderr=true
stdout_logfile=/var/www/visa/storage/logs/queue.log
```

- [ ] **Step 3: `deploy/.env.production.example`**

```
APP_NAME="BOC Visa Portal"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://visa.example.bh

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=visa
DB_USERNAME=visa
DB_PASSWORD=

QUEUE_CONNECTION=redis
CACHE_STORE=redis
SESSION_DRIVER=redis
SESSION_SECURE_COOKIE=true

MAIL_MAILER=ses
MAIL_FROM_ADDRESS=visa@example.bh
MAIL_FROM_NAME="BOC Visa Portal"

AWS_DEFAULT_REGION=me-south-1
AWS_BUCKET=boc-visa-files
BACKUP_BUCKET=boc-visa-backups
VISA_DISK=s3

TESSERACT_PATH=/usr/bin/tesseract
TAP_SECRET_KEY=
ADMIN_IP_ALLOWLIST=
VISA_FILE_RETENTION_DAYS=90
INVOICE_ADDRESS="Manama, Kingdom of Bahrain"
```

(No AWS keys: the EC2 instance role supplies credentials.)

- [ ] **Step 4: `deploy/README.md`** — runbook:

```markdown
# Deploying BOC Visa Portal (AWS me-south-1)

## One-time AWS setup
1. Enable the me-south-1 (Bahrain) region on the AWS account (opt-in region).
2. S3: create `boc-visa-files` and `boc-visa-backups`; Block Public Access ON; default encryption SSE-KMS;
   lifecycle rule on `boc-visa-backups`: expire objects after 30 days.
3. IAM role for EC2 with s3:GetObject/PutObject/DeleteObject/ListBucket on both buckets, kms:Encrypt/Decrypt on the key, ses:SendRawEmail.
4. SES (me-south-1): verify the sending domain (DKIM), request production access.
5. EC2: Ubuntu 24.04, t3.medium, 30 GB gp3 encrypted EBS, attach the IAM role. Security group: 80/443 from anywhere, 22 from BOC IPs only.
6. Data Lifecycle Manager: daily EBS snapshot policy, keep 7.

## Server packages
    sudo apt update && sudo apt install -y nginx mysql-server redis-server tesseract-ocr supervisor certbot python3-certbot-nginx unzip git \
      php8.3-fpm php8.3-cli php8.3-mysql php8.3-mbstring php8.3-xml php8.3-curl php8.3-zip php8.3-gd php8.3-intl php8.3-bcmath php8.3-redis
    # composer: https://getcomposer.org/download/ ; node 20 for the build

## MySQL (localhost only)
    sudo mysql -e "CREATE DATABASE visa; CREATE USER 'visa'@'localhost' IDENTIFIED BY '<strong>'; GRANT ALL ON visa.* TO 'visa'@'localhost';"
    # /etc/mysql/mysql.conf.d/mysqld.cnf → bind-address = 127.0.0.1

## App
    sudo git clone https://github.com/er-athulbaby/visa.git /var/www/visa && cd /var/www/visa
    cp deploy/.env.production.example .env   # fill in secrets
    composer install --no-dev --optimize-autoloader
    npm ci && npm run build
    php artisan key:generate && php artisan migrate --force
    php artisan config:cache && php artisan route:cache && php artisan view:cache && php artisan filament:optimize
    sudo chown -R www-data:www-data storage bootstrap/cache

## Web, queue, scheduler
    sudo cp deploy/nginx.conf /etc/nginx/sites-available/visa && sudo ln -s /etc/nginx/sites-available/visa /etc/nginx/sites-enabled/
    sudo certbot --nginx -d visa.example.bh
    sudo cp deploy/supervisor.conf /etc/supervisor/conf.d/visa.conf && sudo supervisorctl reread && sudo supervisorctl update
    echo "* * * * * www-data cd /var/www/visa && php artisan schedule:run >> /dev/null 2>&1" | sudo tee /etc/cron.d/visa

## First admin
    php artisan make:filament-user        # name, email, password
    php artisan visa:make-admin admin@boc.bh
    # log in at /admin and set up 2FA

## Tap
    Set TAP_SECRET_KEY (live key only after sandbox sign-off). In the Tap dashboard, no webhook URL is needed —
    each charge sends `post.url` = https://visa.example.bh/webhooks/tap.

## Verify after deploy
- `php artisan db:backup` → object appears in s3://boc-visa-backups/mysql/
- Register → add applicant (OCR fills fields from a real passport photo) → pay with Tap test card → invoice email arrives
- Processor: claim → applied → issue visa → applicant receives visa email
- `curl -I https://visa.example.bh/admin` from a non-allowlisted IP returns 403 (if allowlist set)

## Updating
    cd /var/www/visa && git pull && composer install --no-dev -o && npm ci && npm run build \
      && php artisan migrate --force && php artisan optimize && sudo supervisorctl restart visa-queue:*
```

- [ ] **Step 5: Commit and push** (push only once the user approves)

```bash
git add deploy && git commit -m "docs: AWS me-south-1 deployment runbook and server configs"
git push -u origin main
```

---

## Spec coverage check

| Spec section | Task |
|---|---|
| Events per BOC event, fee, deadline, open/closed, vat_rate | 2, 13 |
| Full accounts + email verification | 1 (kit), 2 (`MustVerifyEmail`), 7 (`verified` middleware) |
| Groups: one account, many applicants, one payment | 7, 10 |
| Status flow + audit log + notifications | 2, 3 |
| MRZ OCR, applicant reviews every field, PDF skips OCR | 4, 5, 7 |
| Validation: 6-month expiry, duplicate passport, open event | 7 |
| Tap hosted checkout, webhook as truth, idempotent, 30-min expiry, reconciliation | 8, 10, 11 |
| Invoices, sequential numbering, credit notes, resend | 9, 15 |
| Processor queue, claim/correction/applied/issue/reject, previews, wa.me, bulk zip | 14 |
| Finance read-only payments, filters, totals, export, mark refunded, widget | 15 |
| Admin events, staff users, override, applicant export | 13, 14 |
| Private files, signed URLs, policies, Finance no passport access | 6, 14, 15 |
| 2FA required, IP allowlist, rate limits (kit login throttling), upload re-encoding | 12, 5, 1 |
| Retention purge, backups, MySQL localhost, HTTPS | 11, 17 |
| Claude Design UI | 16 |
| AWS me-south-1 hosting | 17 |
```
