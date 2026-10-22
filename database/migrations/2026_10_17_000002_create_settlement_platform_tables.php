<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->string('employee_number')->unique();
            $table->string('full_name');
            $table->string('national_id_or_iqama')->nullable();
            $table->string('job_title')->nullable();
            $table->string('department')->nullable();
            $table->date('hire_date');
            $table->string('status')->default('active');
            $table->timestamps();
        });

        Schema::create('contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('contract_type');
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->decimal('basic_salary', 12, 2);
            $table->decimal('housing_allowance', 12, 2)->default(0);
            $table->decimal('transport_allowance', 12, 2)->default(0);
            $table->decimal('fixed_allowances', 12, 2)->default(0);
            $table->decimal('commissions', 12, 2)->default(0);
            $table->decimal('other_allowances', 12, 2)->default(0);
            $table->string('status')->default('active');
            $table->timestamps();
        });

        Schema::create('salary_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('type');
            $table->decimal('amount', 12, 2);
            $table->boolean('include_in_settlement')->default(false);
            $table->boolean('include_in_end_of_service')->default(false);
            $table->timestamps();
        });

        Schema::create('termination_types', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('labor_rules', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('article_number')->nullable();
            $table->string('title');
            $table->string('category');
            $table->text('description');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('source_name');
            $table->string('source_url')->nullable();
            $table->string('version')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('settlement_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('termination_type_id')->nullable()->constrained()->nullOnDelete();
            $table->string('rule_code')->unique();
            $table->string('rule_name');
            $table->string('category');
            $table->json('condition_json')->nullable();
            $table->string('calculation_method');
            $table->unsignedBigInteger('legal_rule_id')->nullable();
            $table->integer('priority')->default(100);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $table->foreignId('termination_type_id')->nullable()->constrained()->nullOnDelete();
            $table->date('termination_date');
            $table->date('last_working_date');
            $table->date('resignation_date')->nullable();
            $table->boolean('notice_completed')->nullable();
            $table->text('notes')->nullable();
            $table->decimal('gross_entitlements', 12, 2)->default(0);
            $table->decimal('total_deductions', 12, 2)->default(0);
            $table->decimal('net_settlement', 12, 2)->default(0);
            $table->string('status')->default('draft');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('settlement_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('settlement_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('code');
            $table->string('name');
            $table->decimal('quantity', 12, 2)->nullable();
            $table->decimal('rate', 12, 2)->nullable();
            $table->decimal('amount', 12, 2);
            $table->json('calculation_details_json')->nullable();
            $table->unsignedBigInteger('legal_rule_id')->nullable();
            $table->timestamps();
        });

        Schema::create('settlement_deductions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('settlement_id')->constrained()->cascadeOnDelete();
            $table->string('code');
            $table->string('name');
            $table->decimal('quantity', 12, 2)->nullable();
            $table->decimal('amount', 12, 2);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action');
            $table->string('entity_type');
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->json('old_values_json')->nullable();
            $table->json('new_values_json')->nullable();
            $table->string('ip_address')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('settlement_deductions');
        Schema::dropIfExists('settlement_items');
        Schema::dropIfExists('settlements');
        Schema::dropIfExists('settlement_rules');
        Schema::dropIfExists('labor_rules');
        Schema::dropIfExists('termination_types');
        Schema::dropIfExists('salary_components');
        Schema::dropIfExists('contracts');
        Schema::dropIfExists('employees');
    }
};
