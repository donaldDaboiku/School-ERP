<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grading_systems', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools');
            $table->string('name'); // e.g., "Percentage", "Letter Grade", "CGPA 5.0", "CGPA 4.0"
            $table->string('code')->unique();
            $table->json('grade_rules'); // Store as JSON: [{"min":0,"max":39,"grade":"F"},...]
            $table->decimal('maximum_score', 8, 2);
            $table->decimal('minimum_score', 8, 2);
            $table->boolean('is_default')->default(false);
            $table->enum('system_type', ['percentage', 'letter', 'cgpa', 'points', 'custom'])->default('percentage');
            $table->text('description')->nullable();
            $table->timestamps();
            $table->softDeletes();
            
            $table->unique(['school_id', 'name']);
            $table->index(['school_id', 'is_default']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grading_systems');
    }
};