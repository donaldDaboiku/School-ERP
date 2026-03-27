<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('classes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools');
            $table->foreignId('class_level_id')->constrained('class_levels');
            $table->string('name'); // e.g., "Primary 1A", "JSS 1 Red"
            $table->string('code')->unique();
            $table->string('room_number')->nullable();
            $table->integer('capacity')->default(30);
            $table->integer('student_count')->default(0);
            $table->foreignId('class_teacher_id')->nullable()->constrained('users');
            $table->foreignId('academic_session_id')->constrained('academic_sessions');
            $table->enum('status', ['active', 'inactive', 'completed'])->default('active');
            $table->timestamps();
            $table->softDeletes();
            
            $table->unique(['school_id', 'academic_session_id', 'name']);
            $table->index(['school_id', 'class_teacher_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('classes');
    }
};