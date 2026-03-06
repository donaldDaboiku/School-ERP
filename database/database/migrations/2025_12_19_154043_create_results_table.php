<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('results', function (Blueprint $table) {
            $table->id();

            $table->foreignId('school_id')->constrained('schools');
            $table->foreignId('student_id')->constrained('students');
            $table->foreignId('class_id')->constrained('classes');
            $table->foreignId('subject_id')->constrained('subjects');

            $table->foreignId('assessment_id')->nullable()->constrained('assessments');
            $table->foreignId('exam_id')->nullable()->constrained('exams');

            $table->foreignId('academic_session_id')->constrained('academic_sessions');
            $table->foreignId('term_id')->constrained('terms_semesters');

            $table->decimal('marks_obtained', 8, 2);
            $table->decimal('total_marks', 8, 2);

            // ✅ STORED generated column (indexable)
            $table->decimal('percentage', 5, 2)
                ->storedAs('(marks_obtained / total_marks) * 100');

            $table->string('grade')->nullable();
            $table->decimal('grade_point', 3, 2)->nullable();
            $table->text('teacher_comment')->nullable();

            $table->enum('status', ['draft', 'published', 'archived'])->default('draft');
            $table->foreignId('graded_by')->constrained('users');
            $table->timestamp('published_at')->nullable();
            $table->boolean('is_finalized')->default(false);

            $table->timestamps();

            $table->unique(
                ['student_id', 'subject_id', 'assessment_id', 'exam_id', 'term_id'],
                'results_unique_entry'
            );

            $table->index(['school_id', 'class_id', 'subject_id', 'term_id'], 'results_school_class_subject');
            $table->index(['student_id', 'academic_session_id'], 'results_student_session');
            $table->index('percentage', 'results_percentage_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('results');
    }
};
