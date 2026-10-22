<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\LaborRule;
use App\Models\TerminationType;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        User::firstOrCreate(
            ['email' => 'admin@saudihr.local'],
            [
                'name' => 'System Admin',
                'password' => Hash::make('password123'),
                'role' => UserRole::ADMIN->value,
                'status' => 'active',
            ]
        );

        User::firstOrCreate(
            ['email' => 'hr@saudihr.local'],
            [
                'name' => 'HR Manager',
                'password' => Hash::make('password123'),
                'role' => UserRole::HR->value,
                'status' => 'active',
            ]
        );

        User::firstOrCreate(
            ['email' => 'reviewer@saudihr.local'],
            [
                'name' => 'Settlement Reviewer',
                'password' => Hash::make('password123'),
                'role' => UserRole::REVIEWER->value,
                'status' => 'active',
            ]
        );

        TerminationType::updateOrCreate(
            ['code' => 'resignation'],
            [
                'name_ar' => 'استقالة',
                'name_en' => 'Resignation',
                'description' => 'Normal resignation termination.',
                'is_active' => true,
            ]
        );

        TerminationType::updateOrCreate(
            ['code' => 'termination'],
            [
                'name_ar' => 'فصل',
                'name_en' => 'Termination',
                'description' => 'Employer termination case.',
                'is_active' => true,
            ]
        );

        LaborRule::updateOrCreate(
            ['code' => 'labor-1'],
            [
                'article_number' => 'Article 16',
                'title' => 'End of service entitlement',
                'category' => 'end_of_service',
                'description' => 'Entitlement to settlement for service completion in accordance with Saudi labor law.',
                'effective_from' => '2024-01-01',
                'source_name' => 'Saudi Labor Law',
                'is_active' => true,
            ]
        );
    }
}
