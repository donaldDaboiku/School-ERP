<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            // Laravel Default
            $table->id();
            $table->string('name');
            $table->string('username')->unique()->nullable();
            $table->boolean('is_system_user')->default(false);
            // $table->string('status')->default('active'); // can not have two status column
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
            
            // School ERP Columns
            $table->foreignId('school_id')->nullable()->constrained('schools')->nullOnDelete();
            $table->enum('user_type', [
                'super_admin',
                'admin',
                'principal',
                'teacher',
                'student',
                'parent',
                'accountant',
                'librarian',
                'staff'
            ])->default('staff');
            $table->enum('status', ['active', 'inactive', 'suspended', 'graduated'])->default('active');
            
            // Personal Info
            $table->string('phone')->nullable();
            $table->string('avatar')->nullable();
            $table->enum('gender', ['male', 'female', 'other'])->nullable();
            $table->date('date_of_birth')->nullable();
            $table->text('address')->nullable();
            $table->string('nationality')->nullable();
            
            // Identification
            $table->string('id_number')->nullable()->unique();
            $table->string('employee_id')->nullable()->unique(); // For staff
            $table->string('student_id')->nullable()->unique(); // For students
            
            // Security & Tracking
            $table->timestamp('last_login_at')->nullable();
            $table->ipAddress('last_login_ip')->nullable();
            $table->timestamp('password_changed_at')->nullable();
            $table->softDeletes();
            
            // Indexes
            $table->index(['school_id', 'user_type', 'status']);
            $table->index('email_verified_at');
            $table->index('last_login_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};