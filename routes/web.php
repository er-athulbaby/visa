<?php

use App\Http\Controllers\ApplicantFileController;
use App\Livewire\ApplicantForm;
use App\Livewire\Dashboard;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Route::get('/', function () {
    return view('welcome');
})->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/dashboard', Dashboard::class)->name('dashboard');
    Route::get('/events/{event:slug}/applicants/create', ApplicantForm::class)->name('applicants.create');
    Route::get('/applicants/{applicant}/edit', ApplicantForm::class)->name('applicants.edit');
});

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', 'settings/profile');

    Volt::route('settings/profile', 'settings.profile')->name('settings.profile');
    Volt::route('settings/password', 'settings.password')->name('settings.password');
    Volt::route('settings/appearance', 'settings.appearance')->name('settings.appearance');
});

Route::middleware('auth')->group(function () {
    Route::get('/files/applicants/{applicant}/{kind}', ApplicantFileController::class)
        ->whereIn('kind', ['passport', 'photo', 'visa'])
        ->name('applicant.file');
});

require __DIR__.'/auth.php';
