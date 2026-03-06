<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('students', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users');
            $table->foreignId('school_id')->constrained('schools');
            $table->foreignId('class_id')->nullable()->constrained('classes');
            $table->string('admission_number')->unique();
            $table->date('admission_date');
            $table->enum('admission_type', ['new', 'transfer', 'repeat'])->default('new');
            $table->enum('student_category', ['day', 'boarding', 'special_needs'])->default('day');
            
            // Academic Info
            $table->string('previous_school')->nullable();
            $table->decimal('previous_grade', 5, 2)->nullable();
            $table->enum('academic_stream', ['science', 'arts', 'commercial', 'general'])->nullable();
            
            // Health Info
            $table->string('blood_group', 5)->nullable();
            $table->string('genotype', 5)->nullable();
            $table->text('health_conditions')->nullable();
            $table->text('allergies')->nullable();
            
            // Emergency Contact
            $table->string('emergency_contact_name');
            $table->string('emergency_contact_phone');
            $table->string('emergency_contact_relationship');
            
            // Parent Info (if not linked via parent_student table)
            $table->string('father_name')->nullable();
            $table->string('father_phone')->nullable();
            $table->string('father_email')->nullable();
            $table->string('mother_name')->nullable();
            $table->string('mother_phone')->nullable();
            $table->string('mother_email')->nullable();
            $table->string('guardian_name')->nullable();
            $table->string('guardian_phone')->nullable();
            $table->string('guardian_email')->nullable();
            $table->string('guardian_relationship')->nullable();
            
            // Additional
            $table->text('notes')->nullable();
            $table->json('custom_fields')->nullable();
            $table->timestamps();
            $table->softDeletes();
            
            // Indexes
            $table->index(['school_id', 'class_id']);
            $table->index('admission_number');
            $table->index(['school_id', 'admission_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('students');
    }
};