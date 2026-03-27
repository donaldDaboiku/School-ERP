<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subjects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools');
            $table->string('name'); // e.g., "Mathematics", "English Language"
            $table->string('code')->unique();
            $table->string('short_name')->nullable(); // e.g., "MATH", "ENG"
            $table->enum('type', ['core', 'elective', 'extra_curricular'])->default('core');
            $table->integer('position')->default(1);
            $table->boolean('has_practical')->default(false);
            $table->decimal('max_score', 8, 2)->default(100.00);
            $table->decimal('pass_score', 8, 2)->default(40.00);
            $table->text('description')->nullable();
            $table->timestamps();
            $table->softDeletes();
            
            $table->unique(['school_id', 'name']);
            $table->index(['school_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subjects');
    }
};