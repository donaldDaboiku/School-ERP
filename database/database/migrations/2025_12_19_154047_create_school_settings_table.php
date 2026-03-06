<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('school_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools');
            $table->string('setting_key');
            $table->text('setting_value')->nullable();
            $table->string('data_type')->default('string'); // string, integer, boolean, json, array
            $table->string('category')->default('general'); // academic, financial, security, communication, system
            $table->text('description')->nullable();
            $table->boolean('is_public')->default(false);
            $table->boolean('is_encrypted')->default(false);
            $table->boolean('is_required')->default(false);
            $table->json('validation_rules')->nullable();
            $table->json('allowed_values')->nullable();
            $table->timestamps();
            
            $table->unique(['school_id', 'setting_key']);
            $table->index(['school_id', 'category']);
            $table->index('is_public');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('school_settings');
    }
};