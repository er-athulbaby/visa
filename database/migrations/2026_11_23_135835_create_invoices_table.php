<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
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
};
