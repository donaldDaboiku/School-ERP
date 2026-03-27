<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Role;
use App\Models\School;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $school = School::first();

        if (! $school) {
            return; // safety guard
        }

        $roles = [
            ['name' => 'Super Admin', 'slug' => 'super-admin'],
            ['name' => 'Admin', 'slug' => 'admin'],
            ['name' => 'Principal', 'slug' => 'principal'],
            ['name' => 'Teacher', 'slug' => 'teacher'],
            ['name' => 'Student', 'slug' => 'student'],
            ['name' => 'Parent', 'slug' => 'parent'],
        ];

        foreach ($roles as $role) {
            Role::firstOrCreate(
                [
                    'slug' => $role['slug'],
                    'school_id' => $school->id,
                ],
                [
                    'name' => $role['name'],
                    'is_active' => true,
                ]
            );
        }
    }
}
