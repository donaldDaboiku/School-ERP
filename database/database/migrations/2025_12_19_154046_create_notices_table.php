<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools');
            $table->string('title');
            $table->text('content');
            $table->enum('type', ['general', 'academic', 'event', 'holiday', 'emergency', 'exam', 'fee'])->default('general');
            $table->enum('priority', ['low', 'medium', 'high', 'urgent'])->default('medium');
            $table->enum('target_audience', ['all', 'students', 'teachers', 'parents', 'staff', 'specific_class'])->default('all');
            $table->json('target_classes')->nullable(); // If specific_class
            $table->foreignId('published_by')->constrained('users');
            $table->timestamp('published_at')->useCurrent();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_published')->default(true);
            $table->boolean('send_notification')->default(false);
            $table->timestamp('notification_sent_at')->nullable();
            $table->integer('views_count')->default(0);
            $table->json('attachments')->nullable();
            $table->timestamps();
            $table->softDeletes();
            
            $table->index(['school_id', 'type', 'is_published']);
            $table->index(['published_at', 'expires_at']);
            $table->index('target_audience');
            $table->fullText(['title', 'content']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notices');
    }
};