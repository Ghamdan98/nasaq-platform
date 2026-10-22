<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contract_id')->nullable()->constrained()->nullOnDelete();
            $table->string('transaction_type');
            $table->date('transaction_date');
            $table->string('reference_number')->nullable();
            $table->string('status')->default('draft');
            $table->string('review_status')->default('valid');
            $table->decimal('gross_amount', 12, 2)->default(0);
            $table->decimal('total_deductions', 12, 2)->default(0);
            $table->decimal('net_amount', 12, 2)->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('final_settlement_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hr_transaction_id')->constrained('hr_transactions')->cascadeOnDelete();
            $table->string('termination_type')->nullable();
            $table->date('termination_date')->nullable();
            $table->date('last_working_date')->nullable();
            $table->date('hire_date')->nullable();
            $table->decimal('service_years', 12, 2)->default(0);
            $table->decimal('service_months', 12, 2)->default(0);
            $table->decimal('service_days', 12, 2)->default(0);
            $table->string('contract_type')->nullable();
            $table->decimal('basic_salary', 12, 2)->default(0);
            $table->decimal('gross_entitlements', 12, 2)->default(0);
            $table->decimal('total_deductions', 12, 2)->default(0);
            $table->decimal('net_settlement', 12, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('leave_settlement_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hr_transaction_id')->constrained('hr_transactions')->cascadeOnDelete();
            $table->string('leave_transaction_type')->nullable();
            $table->date('leave_period_from')->nullable();
            $table->date('leave_period_to')->nullable();
            $table->date('leave_start_date')->nullable();
            $table->date('leave_end_date')->nullable();
            $table->decimal('opening_balance', 12, 2)->default(0);
            $table->decimal('accrued_days', 12, 2)->default(0);
            $table->decimal('used_days', 12, 2)->default(0);
            $table->decimal('adjustment_days', 12, 2)->default(0);
            $table->decimal('available_days', 12, 2)->default(0);
            $table->decimal('requested_leave_days', 12, 2)->default(0);
            $table->decimal('days_to_compensate', 12, 2)->default(0);
            $table->string('balance_source')->nullable();
            $table->decimal('calculation_wage', 12, 2)->default(0);
            $table->decimal('daily_rate', 12, 2)->default(0);
            $table->decimal('gross_leave_amount', 12, 2)->default(0);
            $table->decimal('remaining_balance', 12, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_settlement_details');
        Schema::dropIfExists('final_settlement_details');
        Schema::dropIfExists('hr_transactions');
    }
};
