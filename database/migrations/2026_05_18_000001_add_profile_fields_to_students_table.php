<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            if (! Schema::hasColumn('students', 'nationality')) {
                $table->string('nationality')->nullable()->after('genotype');
            }

            if (! Schema::hasColumn('students', 'state_of_origin')) {
                $table->string('state_of_origin')->nullable()->after('nationality');
            }

            if (! Schema::hasColumn('students', 'religion')) {
                $table->string('religion')->nullable()->after('state_of_origin');
            }

            if (! Schema::hasColumn('students', 'status')) {
                $table->enum('status', ['active', 'inactive', 'suspended', 'graduated'])->default('active')->after('religion');
            }
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $columns = ['nationality', 'state_of_origin', 'religion', 'status'];

            foreach ($columns as $column) {
                if (Schema::hasColumn('students', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
