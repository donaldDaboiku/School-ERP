<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools');
            $table->foreignId('student_id')->constrained('students');
            $table->foreignId('class_id')->constrained('classes');
            $table->foreignId('academic_session_id')->constrained('academic_sessions');
            $table->foreignId('term_id')->constrained('terms_semesters');
            $table->date('attendance_date');
            $table->enum('status', ['present', 'absent', 'late', 'excused', 'half_day', 'sick'])->default('present');
            $table->time('check_in_time')->nullable();
            $table->time('check_out_time')->nullable();
            $table->text('reason')->nullable();
            $table->foreignId('recorded_by')->constrained('users');
            $table->boolean('notified_parent')->default(false);
            $table->timestamp('notification_sent_at')->nullable();
            $table->timestamps();
            
            $table->unique(['student_id', 'attendance_date']);
            $table->index(['school_id', 'class_id', 'attendance_date', 'status']);
            $table->index(['student_id', 'academic_session_id', 'term_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendances');
    }
};