<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools');
            $table->foreignId('subject_id')->constrained('subjects');
            $table->foreignId('class_id')->constrained('classes');
            $table->foreignId('term_id')->constrained('terms_semesters');
            $table->string('title');
            $table->enum('type', ['exam', 'quiz', 'assignment', 'project', 'test', 'practical'])->default('exam');
            $table->decimal('total_marks', 8, 2);
            $table->decimal('passing_marks', 8, 2);
            $table->decimal('weightage', 5, 2)->default(100.00); // Percentage weight in final grade
            $table->date('assessment_date');
            $table->date('due_date')->nullable();
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->text('instructions')->nullable();
            $table->text('description')->nullable();
            $table->json('attachments')->nullable();
            $table->enum('status', ['draft', 'scheduled', 'ongoing', 'completed', 'cancelled'])->default('draft');
            $table->timestamps();
            $table->softDeletes();
            
            $table->index(['school_id', 'class_id', 'subject_id', 'term_id']);
            $table->index(['assessment_date', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assessments');
    }
};