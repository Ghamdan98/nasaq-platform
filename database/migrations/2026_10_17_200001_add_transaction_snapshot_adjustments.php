<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_transactions', function (Blueprint $table) {
            $table->json('employee_snapshot_json')->nullable()->after('notes');
            $table->json('contract_snapshot_json')->nullable()->after('employee_snapshot_json');
            $table->json('salary_snapshot_json')->nullable()->after('contract_snapshot_json');
            $table->json('input_snapshot_json')->nullable()->after('salary_snapshot_json');
            $table->json('calculation_snapshot_json')->nullable()->after('input_snapshot_json');
            $table->json('legal_rules_snapshot_json')->nullable()->after('calculation_snapshot_json');
            $table->json('warnings_snapshot_json')->nullable()->after('legal_rules_snapshot_json');
        });

        Schema::create('hr_transaction_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hr_transaction_id')->constrained('hr_transactions')->cascadeOnDelete();
            $table->string('type')->nullable();
            $table->string('code');
            $table->string('name')->nullable();
            $table->string('name_ar')->nullable();
            $table->decimal('quantity', 12, 2)->nullable();
            $table->decimal('rate', 12, 2)->nullable();
            $table->decimal('calculated_amount', 12, 2)->default(0);
            $table->decimal('approved_amount', 12, 2)->default(0);
            $table->decimal('difference_amount', 12, 2)->default(0);
            $table->boolean('was_manually_adjusted')->default(false);
            $table->text('adjustment_reason')->nullable();
            $table->foreignId('adjusted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('adjusted_at')->nullable();
            $table->json('legal_reference_json')->nullable();
            $table->json('calculation_details_json')->nullable();
            $table->timestamps();
        });

        Schema::create('hr_transaction_deductions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hr_transaction_id')->constrained('hr_transactions')->cascadeOnDelete();
            $table->string('code');
            $table->string('name')->nullable();
            $table->string('name_ar')->nullable();
            $table->decimal('quantity', 12, 2)->nullable();
            $table->decimal('rate', 12, 2)->nullable();
            $table->decimal('calculated_amount', 12, 2)->default(0);
            $table->decimal('approved_amount', 12, 2)->default(0);
            $table->decimal('difference_amount', 12, 2)->default(0);
            $table->boolean('was_manually_adjusted')->default(false);
            $table->text('adjustment_reason')->nullable();
            $table->foreignId('adjusted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('adjusted_at')->nullable();
            $table->json('legal_reference_json')->nullable();
            $table->json('calculation_details_json')->nullable();
            $table->timestamps();
        });

        Schema::create('transaction_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hr_transaction_id')->constrained('hr_transactions')->cascadeOnDelete();
            $table->string('item_type');
            $table->unsignedBigInteger('item_id');
            $table->decimal('calculated_amount', 12, 2)->default(0);
            $table->decimal('old_approved_amount', 12, 2)->default(0);
            $table->decimal('new_approved_amount', 12, 2)->default(0);
            $table->decimal('difference_amount', 12, 2)->default(0);
            $table->text('reason')->nullable();
            $table->foreignId('adjusted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_adjustments');
        Schema::dropIfExists('hr_transaction_deductions');
        Schema::dropIfExists('hr_transaction_items');

        Schema::table('hr_transactions', function (Blueprint $table) {
            $table->dropColumn([
                'employee_snapshot_json',
                'contract_snapshot_json',
                'salary_snapshot_json',
                'input_snapshot_json',
                'calculation_snapshot_json',
                'legal_rules_snapshot_json',
                'warnings_snapshot_json',
            ]);
        });
    }
};
