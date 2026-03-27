<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parent_student', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->constrained('parents')->onDelete('cascade');
            $table->foreignId('student_id')->constrained('students')->onDelete('cascade');
            $table->enum('relationship', ['father', 'mother', 'guardian', 'sibling', 'other'])->default('guardian');
            $table->boolean('is_primary')->default(false);
            $table->boolean('has_custody')->default(false);
            $table->boolean('receives_notifications')->default(true);
            $table->boolean('is_emergency_contact')->default(false);
            $table->date('relationship_since')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            
            $table->unique(['parent_id', 'student_id', 'relationship']);
            $table->index(['student_id', 'is_primary']);
            $table->index(['parent_id', 'has_custody']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parent_student');
    }
};