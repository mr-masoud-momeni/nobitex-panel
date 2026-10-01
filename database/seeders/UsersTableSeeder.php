<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UsersTableSeeder extends Seeder
{
    public function run()
    {
        $password = env('SEED_ADMIN_PASSWORD', 'change-me');

        $user = User::create([
            'name' => env('SEED_ADMIN_NAME', 'Admin'),
            'email' => env('SEED_ADMIN_EMAIL', 'admin@example.com'),
            'phone' => env('SEED_ADMIN_PHONE'),
            'password' => Hash::make($password),
            'email_verified_at' => now(),
        ]);

        $user->attachRole('admin');
    }
}
