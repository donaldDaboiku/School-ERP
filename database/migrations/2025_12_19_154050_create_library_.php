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
        Schema::create('library_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('book_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete(); // student or staff
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();

            $table->date('borrowed_at');
            $table->date('due_at');
            $table->date('returned_at')->nullable();

            $table->enum('status', ['borrowed', 'returned', 'overdue'])
                ->default('borrowed');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('library_');
    }
};
