<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('remarks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools');
            $table->foreignId('student_id')->constrained('students');
            $table->foreignId('teacher_id')->constrained('users');
            $table->foreignId('academic_session_id')->constrained('academic_sessions');
            $table->foreignId('term_id')->constrained('terms_semesters');
            $table->text('remark');
            $table->enum('type', ['academic', 'behavior', 'attendance', 'discipline', 'achievement', 'general'])->default('general');
            $table->enum('sentiment', ['positive', 'negative', 'neutral'])->default('neutral');
            $table->date('date');
            $table->boolean('visible_to_parent')->default(true);
            $table->boolean('requires_action')->default(false);
            $table->enum('action_status', ['pending', 'in_progress', 'resolved', 'closed'])->nullable();
            $table->timestamp('action_resolved_at')->nullable();
            $table->text('action_notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            
            $table->index(['school_id', 'student_id', 'type']);
            $table->index(['date', 'teacher_id']);
            $table->index('requires_action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('remarks');
    }
};