<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_advances', function (Blueprint $t) {
            $t->id();
            $t->foreignId('employee_id')->constrained()->restrictOnDelete();
            $t->string('company');
            $t->string('reference_number', 100);
            $t->unique(['company', 'reference_number']);
            $t->decimal('original_amount', 12, 2);
            $t->decimal('monthly_installment', 12, 2)->nullable();
            $t->json('repayment_plan')->nullable();
            $t->string('payment_method')->nullable();
            $t->date('planned_disbursement_date')->nullable();
            $t->date('disbursed_at')->nullable();
            $t->string('status')->default('requested');
            $t->boolean('is_disputed')->default(false);
            $t->text('notes')->nullable();
            $t->string('approval_reference')->nullable();
            $t->string('evidence_reference')->nullable();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('approved_at')->nullable();
            $t->timestamps();
        });
        Schema::create('advance_transactions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('advance_id')->constrained('employee_advances')->restrictOnDelete();
            $t->string('transaction_type');
            $t->decimal('amount', 12, 2);
            $t->smallInteger('direction');
            $t->date('transaction_date');
            $t->string('reference_number', 150);
            $t->string('idempotency_key', 150)->unique();
            $t->foreignId('hr_transaction_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('reversal_of_id')->nullable()->unique()->constrained('advance_transactions')->restrictOnDelete();
            $t->text('notes')->nullable();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
        });
        Schema::create('settlement_advance_allocations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hr_transaction_id')->constrained()->restrictOnDelete();
            $t->foreignId('advance_id')->constrained('employee_advances')->restrictOnDelete();
            $t->decimal('allocated_amount', 12, 2);
            $t->string('status')->default('reserved');
            $t->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('posted_at')->nullable();
            $t->timestamps();
            $t->unique(['hr_transaction_id', 'advance_id']);
        });
        Schema::create('advance_documents', function (Blueprint $t) {
            $t->id();
            $t->foreignId('advance_id')->constrained('employee_advances')->restrictOnDelete();
            $t->string('category');
            $t->string('name');
            $t->string('path');
            $t->string('mime');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
        });
        Schema::create('settlement_payments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hr_transaction_id')->constrained()->restrictOnDelete();
            $t->string('reference_number', 150)->unique();
            $t->decimal('amount', 12, 2);
            $t->date('payment_date');
            $t->string('method');
            $t->foreignId('reversal_of_id')->nullable()->unique()->constrained('settlement_payments')->restrictOnDelete();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->text('notes')->nullable();
            $t->timestamps();
        });
        Schema::table('hr_transactions', function (Blueprint $t) {
            $t->string('financial_stage')->default('draft');
            $t->json('advance_decision_json')->nullable();
            $t->json('financial_review_json')->nullable();
            $t->json('advance_legal_review_json')->nullable();
            $t->foreignId('financial_reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('financial_reviewed_at')->nullable();
            $t->string('posting_key', 150)->nullable()->unique();
            $t->foreignId('posted_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('posted_at')->nullable();
            $t->timestamp('paid_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('hr_transactions', function (Blueprint $t) {
            $t->dropConstrainedForeignId('financial_reviewed_by');
            $t->dropConstrainedForeignId('posted_by');
            $t->dropColumn(['financial_stage', 'advance_decision_json', 'financial_review_json', 'advance_legal_review_json', 'financial_reviewed_at', 'posting_key', 'posted_at', 'paid_at']);
        });
        Schema::dropIfExists('settlement_payments');
        Schema::dropIfExists('advance_documents');
        Schema::dropIfExists('settlement_advance_allocations');
        Schema::dropIfExists('advance_transactions');
        Schema::dropIfExists('employee_advances');
    }
};
