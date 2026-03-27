<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('class_levels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools');
            $table->string('name'); // e.g., "Primary 1", "JSS 1", "SSS 1"
            $table->string('code')->unique();
            $table->string('short_name')->nullable(); // e.g., "P1", "J1", "S1"
            $table->integer('level_order')->default(1);
            $table->enum('category', ['nursery', 'primary', 'junior', 'senior', 'other'])->default('primary');
            $table->decimal('fee_amount', 12, 2)->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
            $table->softDeletes();
            
            $table->unique(['school_id', 'name']);
            $table->index(['school_id', 'category', 'level_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('class_levels');
    }
};