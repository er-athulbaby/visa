<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
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
};
