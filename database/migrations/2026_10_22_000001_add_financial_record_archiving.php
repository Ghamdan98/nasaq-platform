<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['employee_advances', 'hr_transactions'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->timestamp('archived_at')->nullable()->index());
        }
    }

    public function down(): void
    {
        foreach (['employee_advances', 'hr_transactions'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropIndex(['archived_at']);
                $table->dropColumn('archived_at');
            });
        }
    }
};
