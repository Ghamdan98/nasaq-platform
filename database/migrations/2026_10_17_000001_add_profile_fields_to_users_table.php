<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('hr')->after('email');
            $table->unsignedBigInteger('employee_id')->nullable()->after('role');
            $table->string('status')->default('active')->after('employee_id');
            $table->string('phone')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['role', 'employee_id', 'status', 'phone']);
        });
    }
};
