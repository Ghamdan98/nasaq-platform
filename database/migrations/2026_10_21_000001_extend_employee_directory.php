<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropUnique(['employee_number']);
            $table->string('company')->default('الشركة الرئيسية');
            $table->string('nationality')->nullable();
            $table->date('last_leave_settlement_date')->nullable();
            foreach (['basic_salary', 'allowance_1', 'transportation_allowance', 'work_nature_allowance', 'subsistence_allowance', 'food_allowance', 'additional_fixed_allowance', 'other_allowances', 'housing_allowance'] as $field) {
                $table->decimal($field, 12, 2)->nullable();
            }
            $table->json('salary_inclusion')->nullable();
            $table->unique(['company', 'employee_number']);
            $table->index('full_name');
            $table->index('national_id_or_iqama');
        });
        Schema::create('employee_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('filename');
            $table->string('mode');
            $table->string('status');
            $table->unsignedInteger('new_count')->default(0);
            $table->unsignedInteger('updated_count')->default(0);
            $table->unsignedInteger('rejected_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->longText('payload')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        if (DB::table('employees')->select('employee_number')->groupBy('employee_number')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Cannot roll back company-scoped employee codes while duplicate codes exist across companies.');
        }
        Schema::dropIfExists('employee_imports');
        Schema::table('employees', function (Blueprint $table) {
            $table->dropUnique(['company', 'employee_number']);
            $table->dropIndex(['full_name']);
            $table->dropIndex(['national_id_or_iqama']);
            $table->dropColumn(['company', 'nationality', 'last_leave_settlement_date', 'basic_salary', 'allowance_1', 'transportation_allowance', 'work_nature_allowance', 'subsistence_allowance', 'food_allowance', 'additional_fixed_allowance', 'other_allowances', 'housing_allowance', 'salary_inclusion']);
            $table->unique('employee_number');
        });
    }
};
