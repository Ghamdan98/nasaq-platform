<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('permissions_json')->nullable();
        });

        Schema::table('hr_transactions', function (Blueprint $table) {
            $table->unsignedInteger('version_number')->default(1);
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->foreignId('requested_changes_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('requested_changes_at')->nullable();
            $table->text('requested_changes_reason')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancel_reason')->nullable();
        });

        Schema::create('hr_transaction_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hr_transaction_id')->constrained('hr_transactions')->cascadeOnDelete();
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->text('reason')->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['hr_transaction_id', 'created_at']);
        });

        Schema::create('hr_transaction_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hr_transaction_id')->constrained('hr_transactions')->cascadeOnDelete();
            $table->unsignedInteger('version_number');
            $table->string('status');
            $table->json('snapshot_json');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason');
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['hr_transaction_id', 'version_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_transaction_versions');
        Schema::dropIfExists('hr_transaction_status_history');

        Schema::table('hr_transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('submitted_by');
            $table->dropColumn('submitted_at');
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn('approved_at');
            $table->dropConstrainedForeignId('rejected_by');
            $table->dropColumn(['rejected_at', 'rejection_reason']);
            $table->dropConstrainedForeignId('requested_changes_by');
            $table->dropColumn(['requested_changes_at', 'requested_changes_reason']);
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn(['cancelled_at', 'cancel_reason', 'version_number']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('permissions_json');
        });
    }
};
