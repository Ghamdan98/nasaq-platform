<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void { Schema::table('advance_transactions', fn (Blueprint $table) => $table->unique(['advance_id', 'transaction_type', 'reference_number'], 'advance_movement_document_unique')); }
    public function down(): void { Schema::table('advance_transactions', fn (Blueprint $table) => $table->dropUnique('advance_movement_document_unique')); }
};
