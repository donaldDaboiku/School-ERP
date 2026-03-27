<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('skills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools');
            $table->string('name'); // e.g., "Communication", "Critical Thinking", "Leadership"
            $table->string('code')->unique();
            $table->text('description')->nullable();
            $table->enum('category', ['academic', 'social', 'physical', 'creative', 'moral'])->default('academic');
            $table->integer('max_score')->default(5);
            $table->integer('position')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            
            $table->unique(['school_id', 'name']);
            $table->index(['school_id', 'category', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('skills');
    }
};