<?php

namespace Database\Seeders;

use App\Models\SystemRole;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Get the admin system role
        $adminRole = SystemRole::where('name', 'admin')->first();
        
        if (!$adminRole) {
            $this->command->error('Admin system role not found. Please run SystemRoleSeeder first.');
            return;
        }

        // Create admin user if it doesn't exist
        $adminUser = User::where('email', 'admin@example.com')->first();
        
        if (!$adminUser) {
            User::create([
                'firstname' => 'Admin',
                'middlename' => '',
                'lastname' => 'User',
                'contact_number' => '1234567890',
                'address' => 'Admin Address',
                'email' => 'admin@example.com',
                'email_verified_at' => now(), // Email already verified
                'password' => Hash::make('admin123'), // Default password
                'status' => 'active',
                'systemrole_id' => $adminRole->id,
                'age' => 30,
                'enrolled_school' => null,
                'school_year' => null,
            ]);
            
            $this->command->info('Admin user created successfully.');
            $this->command->info('Email: admin@example.com');
            $this->command->info('Password: admin123');
            $this->command->warn('Please change the default password after first login.');
        } else {
            $this->command->info('Admin user already exists.');
        }
    }
}