<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        $exists = User::where('role', 'super_admin')->exists();
        
        if (!$exists) {
            User::create([
                'name' => 'Super Admin',
                'email' => 'admin@chattoshop.com',
                'password' => Hash::make('admin123'),
                'role' => 'super_admin',
                'email_verified_at' => now(),
            ]);
            
            echo "Super Admin created: admin@chattoshop.com / admin123\n";
        } else {
            echo "Super Admin already exists.\n";
        }
    }
}
