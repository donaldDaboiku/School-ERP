<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('terms_semesters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools');
            $table->foreignId('academic_session_id')->constrained('academic_sessions');
            $table->string('name'); // e.g., "First Term", "Semester 1"
            $table->string('code')->unique();
            $table->integer('order')->default(1);
            $table->date('start_date');
            $table->date('end_date');
            $table->boolean('is_current')->default(false);
            $table->enum('status', ['upcoming', 'active', 'completed'])->default('upcoming');
            $table->decimal('term_fee', 12, 2)->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
            $table->softDeletes();
            
            $table->unique(['academic_session_id', 'name']);
            $table->index(['school_id', 'is_current', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('terms_semesters');
    }
};