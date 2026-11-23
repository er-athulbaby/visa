<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
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
};
