<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_settings', function (Blueprint $table) {
            $table->id();
            $table->string('scope', 100);
            $table->string('group', 50);
            $table->string('key', 150);
            $table->json('value')->nullable();
            $table->string('value_type', 20);
            $table->boolean('is_public');
            $table->boolean('is_editable');
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['scope', 'key']);
        });
        Schema::create('system_branding_assets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('scope', 100);
            $table->string('path');
            $table->string('sha256', 64);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_branding_assets');
        Schema::dropIfExists('system_settings');
    }
};
