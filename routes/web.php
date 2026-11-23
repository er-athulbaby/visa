<?php

use App\Http\Controllers\ApplicantFileController;
use App\Livewire\ApplicantForm;
use App\Livewire\Dashboard;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Route::get('/', fn () => redirect()->route(auth()->check() ? 'dashboard' : 'login'))->name('home');

Route::middleware(['auth', 'verified', 'applicant'])->group(function () {
    Route::get('/dashboard', Dashboard::class)->name('dashboard');
    Route::get('/events/{event:slug}/applicants/create', ApplicantForm::class)->name('applicants.create');
    Route::get('/applicants/{applicant}/edit', ApplicantForm::class)->name('applicants.edit');
});

Route::middleware(['auth', 'applicant'])->group(function () {
    Route::redirect('settings', 'settings/profile');

    Volt::route('settings/profile', 'settings.profile')->name('settings.profile');
    Volt::route('settings/password', 'settings.password')->name('settings.password');
});

Route::post('/webhooks/tap', \App\Http\Controllers\TapWebhookController::class)->name('tap.webhook');

Route::get('/checkout/{payment}/return', \App\Http\Controllers\CheckoutReturnController::class)
    ->middleware(['auth', 'applicant'])
    ->name('checkout.return');

Route::middleware('auth')->group(function () {
    Route::get('/invoices/{invoice}', \App\Http\Controllers\InvoiceDownloadController::class)->name('invoices.download');
    Route::get('/files/applicants/{applicant}/{kind}', ApplicantFileController::class)
        ->whereIn('kind', ['passport', 'photo', 'visa'])
        ->name('applicant.file');
});

require __DIR__.'/auth.php';
