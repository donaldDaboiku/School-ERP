<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users');
            $table->foreignId('school_id')->constrained('schools');
            $table->string('occupation')->nullable();
            $table->string('employer')->nullable();
            $table->string('office_address')->nullable();
            $table->string('office_phone')->nullable();
            $table->decimal('annual_income', 12, 2)->nullable();
            
            // Communication Preferences
            $table->boolean('receive_sms')->default(true);
            $table->boolean('receive_email')->default(true);
            $table->boolean('receive_phone')->default(true);
            $table->boolean('receive_WhatsApp')->default(true);
            $table->boolean('receive_in_person')->default(true);
            $table->boolean('receive_push')->default(true);
            
            // Additional Info
            $table->enum('marital_status', ['single', 'married', 'divorced', 'widowed'])->nullable();
            $table->string('religion')->nullable();
            $table->string('national_id_number')->nullable();
            $table->string('tin_number')->nullable(); // Tax Identification Number
            
            // Emergency Contact (if different from parent)
            $table->string('emergency_alt_name')->nullable();
            $table->string('emergency_alt_phone')->nullable();
            
            // Permissions
            $table->boolean('can_pickup_child')->default(true);
            $table->boolean('can_view_grades')->default(true);
            $table->boolean('can_view_attendance')->default(true);
            
            $table->text('notes')->nullable();
            $table->json('custom_fields')->nullable();
            $table->timestamps();
            $table->softDeletes();
            
            $table->index(['school_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parents');
    }
};