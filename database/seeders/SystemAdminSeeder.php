<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class SystemAdminSeeder extends Seeder
{
    public function run(): void
    {
        $exists = User::where('role', 'system_admin')
            ->where('email', 'gilcrischatto@gmail.com')
            ->exists();
        
        if (!$exists) {
            User::create([
                'name' => 'System Admin',
                'email' => 'gilcrischatto@gmail.com',
                'password' => Hash::make('gilcris123'),
                'role' => 'system_admin',
                'phone' => '09123456789',
                'address' => 'ChattoShop HQ',
                'email_verified_at' => now(),
            ]);
            
            echo "System Admin created: gilcrischatto@gmail.com / gilcris123\n";
        } else {
            // Update password if exists
            User::where('email', 'gilcrischatto@gmail.com')
                ->update([
                    'password' => Hash::make('gilcris123'),
                    'role' => 'system_admin'
                ]);
            echo "System Admin exists, password updated.\n";
        }
    }
}