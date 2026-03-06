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
            $table->foreignId('school_id')->constrained('schools');
            $table->foreignId('student_id')->constrained('students');
            $table->string('invoice_number')->unique();
            $table->enum('payment_type', ['tuition', 'exam', 'uniform', 'transport', 'library', 'sports', 'other'])->default('tuition');
            $table->string('description');
            $table->decimal('amount', 12, 2);
            $table->decimal('amount_paid', 12, 2)->default(0);
            $table->decimal('balance', 12, 2)->default(0);
            $table->date('due_date');
            $table->enum('status', ['pending', 'partial', 'paid', 'overdue', 'cancelled'])->default('pending');
            $table->enum('payment_method', ['cash', 'bank_transfer', 'cheque', 'card', 'mobile_money'])->nullable();
            $table->string('transaction_id')->nullable()->unique();
            $table->date('payment_date')->nullable();
            $table->string('receipt_number')->nullable()->unique();
            $table->foreignId('received_by')->nullable()->constrained('users');
            $table->text('notes')->nullable();
            $table->json('payment_details')->nullable();
            $table->timestamps();
            $table->softDeletes();
            
            $table->index(['school_id', 'student_id', 'status']);
            $table->index(['invoice_number', 'payment_date']);
            $table->index(['due_date', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};