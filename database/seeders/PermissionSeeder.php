<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Permission;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            // users
            ['name' => 'View Users', 'slug' => 'users.view', 'module' => 'users'],
            ['name' => 'Create Users', 'slug' => 'users.create', 'module' => 'users'],
            ['name' => 'Edit Users', 'slug' => 'users.edit', 'module' => 'users'],
            ['name' => 'Delete Users', 'slug' => 'users.delete', 'module' => 'users'],
            // Students
            ['name' => 'View students', 'slug' => 'students.view', 'module' => 'students'],
            ['name' => 'Create students', 'slug' => 'students.create', 'module' => 'students'],
            ['name' => 'Edit students', 'slug' => 'students.edit', 'module' => 'students'],
            ['name' => 'Delete students', 'slug' => 'students.delete', 'module' => 'students'],

            // Teachers
            ['name' => 'View teachers', 'slug' => 'teachers.view', 'module' => 'teachers'],
            ['name' => 'Manage teachers', 'slug' => 'teachers.manage', 'module' => 'teachers'],

            // Classes & Subjects
            ['name' => 'Manage classes', 'slug' => 'classes.manage', 'module' => 'classes'],
            ['name' => 'Manage subjects', 'slug' => 'subjects.manage', 'module' => 'subjects'],

            // Results
            ['name' => 'Create results', 'slug' => 'results.create', 'module' => 'results'],
            ['name' => 'Publish results', 'slug' => 'results.publish', 'module' => 'results'],
            ['name' => 'Approve results', 'slug' => 'results.approve', 'module' => 'results'],

            // Finance
            ['name' => 'Collect fees', 'slug' => 'fees.collect', 'module' => 'finance'],
            ['name' => 'Manage expenses', 'slug' => 'expenses.manage', 'module' => 'finance'],

            // Library
            ['name' => 'Manage books', 'slug' => 'library.manage', 'module' => 'library'],
            ['name' => 'Issue books', 'slug' => 'library.issue', 'module' => 'library'],

            // Inventory
            ['name' => 'View inventory', 'slug' => 'inventory.view', 'module' => 'inventory'],
            ['name' => 'Adjust inventory', 'slug' => 'inventory.adjust', 'module' => 'inventory'],

            // Admin
            ['name' => 'Manage users', 'slug' => 'users.manage', 'module' => 'admin'],
            ['name' => 'Manage roles', 'slug' => 'roles.manage', 'module' => 'admin'],
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(
                ['slug' => $permission['slug']],
                ['name' => $permission['name']]
                // $permission
            );
        }
    }
}
