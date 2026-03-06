<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teachers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users');
            $table->foreignId('school_id')->constrained('schools');
            $table->string('teacher_id')->unique();
            $table->enum('employment_type', ['permanent', 'contract', 'part_time', 'volunteer'])->default('permanent');
            $table->date('employment_date');
            $table->date('contract_end_date')->nullable();
            $table->string('qualification')->nullable();
            $table->string('specialization')->nullable();
            $table->integer('years_of_experience')->default(0);
            $table->enum('salary_scale', ['A', 'B', 'C', 'D', 'E'])->nullable();
            $table->string('bank_name')->nullable();
            $table->string('account_number')->nullable();
            $table->string('account_name')->nullable();
            $table->string('pension_number')->nullable();
            $table->string('tax_identification_number')->nullable();
            
            // Subjects they teach (stored in separate table, but metadata here)
            $table->json('subjects_expertise')->nullable();
            $table->json('classes_assigned')->nullable();
            
            // Additional Info
            $table->enum('teaching_level', ['nursery', 'primary', 'junior', 'senior', 'all'])->default('all');
            $table->boolean('is_class_teacher')->default(false);
            $table->boolean('is_head_of_department')->default(false);
            $table->boolean('is_active')->default(true);    
            $table->string('department')->nullable();
            $table->text('notes')->nullable();
            $table->json('custom_fields')->nullable();
            $table->timestamps();
            $table->softDeletes();
            
            $table->index(['school_id', 'teacher_id']);
            $table->index(['school_id', 'employment_type', 'is_active']);
            $table->index('is_class_teacher');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teachers');
    }
};