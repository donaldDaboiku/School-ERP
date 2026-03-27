<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\School;
use App\Models\Role;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $school = School::first();
        $adminRole = Role::where('slug', 'admin')->first();

        if (! $school || ! $adminRole) {
            return;
        }

        $user = User::firstOrCreate(
            ['email' => 'admin@demo.com'],
            [
                'name' => 'System Admin',
                'password' => Hash::make('password'),
                'school_id' => $school->id,
                'status' => 'active',
            ]
        );

        // Assign role
        $user->roles()->sync([$adminRole->id]);
    }
}
