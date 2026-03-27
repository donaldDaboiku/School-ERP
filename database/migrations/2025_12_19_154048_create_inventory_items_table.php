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
        Schema::create('inventory_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->string('name');                 // Desk, Chalk
            $table->string('category')->nullable(); // Furniture, Stationery
            $table->string('sku')->nullable()->unique();
            $table->integer('quantity')->default(0);
            $table->decimal('unit_cost', 12, 2)->nullable();
            $table->string('unit')->nullable();     // pcs, packs
            $table->integer('reorder_level')->default(0);
            $table->text('description')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_items');
    }
};
