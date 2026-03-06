<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools');
            $table->string('name'); // e.g., "First Term Examination 2024"
            $table->string('code')->unique();
            $table->foreignId('academic_session_id')->constrained('academic_sessions');
            $table->foreignId('term_id')->constrained('terms_semesters');
            $table->date('start_date');
            $table->date('end_date');
            $table->enum('type', ['mid_term', 'end_term', 'final', 'promotion', 'entrance', 'mock'])->default('end_term');
            $table->enum('status', ['draft', 'scheduled', 'ongoing', 'completed', 'cancelled'])->default('draft');
            $table->text('instructions')->nullable();
            $table->text('description')->nullable();
            $table->json('classes_included')->nullable(); // Array of class IDs
            $table->json('subjects_included')->nullable(); // Array of subject IDs
            $table->boolean('results_published')->default(false);
            $table->timestamp('results_published_at')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->softDeletes();
            
            $table->unique(['school_id', 'academic_session_id', 'term_id', 'name']);
            $table->index(['school_id', 'start_date', 'end_date']);
            $table->index('results_published');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exams');
    }
};