<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('user_archives', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('original_user_id');
            $table->json('user_snapshot');
            $table->string('archived_reason')->nullable();
            $table->foreignId('archived_by')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamp('archived_at')->useCurrent();

            $table->index('original_user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_archives');
    }
};
