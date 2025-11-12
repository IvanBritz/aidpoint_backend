<?php

namespace Database\Seeders;

use App\Models\SystemRole;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class SystemRoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Expanded roles to support employee management flows
        $roles = ['admin', 'director', 'employee', 'caseworker', 'finance', 'beneficiary'];
        
        foreach ($roles as $role) {
            SystemRole::firstOrCreate(['name' => $role]);
        }
    }
}
