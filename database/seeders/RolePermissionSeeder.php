<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Role;
use App\Models\Permission;

class RolePermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Get all permissions
        $allPermissions = Permission::all();
        
        // Get all roles
        $superAdminRole = Role::where('slug', 'super-admin')->first();
        $adminRole = Role::where('slug', 'admin')->first();
        $principalRole = Role::where('slug', 'principal')->first();
        $teacherRole = Role::where('slug', 'teacher')->first();
        $studentRole = Role::where('slug', 'student')->first();
        $parentRole = Role::where('slug', 'parent')->first();

        // =====================================================
        // SUPER ADMIN - Gets ALL permissions
        // =====================================================
        if ($superAdminRole) {
            $superAdminRole->permissions()->sync($allPermissions->pluck('id'));
            $this->command->info('Super Admin: ' . $superAdminRole->permissions()->count() . ' permissions');
        }

        // =====================================================
        // ADMIN - Gets ALL permissions
        // =====================================================
        if ($adminRole) {
            $adminRole->permissions()->sync($allPermissions->pluck('id'));
            $this->command->info('Admin: ' . $adminRole->permissions()->count() . ' permissions');
        }

        // =====================================================
        // PRINCIPAL - Gets most permissions except system-level
        // =====================================================
        if ($principalRole) {
            $principalPermissions = Permission::whereIn('slug', [
                'users.view',
                'users.create',
                'users.edit',
                'students.view',
                'students.create',
                'students.edit',
                'students.delete',
                'teachers.view',
                'teachers.manage',
                'classes.manage',
                'subjects.manage',
                'results.create',
                'results.publish',
                'results.approve',
                'fees.collect',
                'expenses.manage',
                'library.manage',
                'library.issue',
                'inventory.view',
                'inventory.adjust',
            ])->pluck('id');
            
            $principalRole->permissions()->sync($principalPermissions);
            $this->command->info('Principal: ' . $principalRole->permissions()->count() . ' permissions');
        }

        // =====================================================
        // TEACHER - Can manage students, classes, and results
        // =====================================================
        if ($teacherRole) {
            $teacherPermissions = Permission::whereIn('slug', [
                'students.view',
                'students.create',
                'students.edit',
                'teachers.view',
                'classes.manage',
                'results.create',
            ])->pluck('id');
            
            $teacherRole->permissions()->sync($teacherPermissions);
            $this->command->info('Teacher: ' . $teacherRole->permissions()->count() . ' permissions');
        }

        // =====================================================
        // STUDENT - Can only issue library books
        // =====================================================
        if ($studentRole) {
            $studentPermissions = Permission::whereIn('slug', [
                'library.issue',
            ])->pluck('id');
            
            $studentRole->permissions()->sync($studentPermissions);
            $this->command->info('Student: ' . $studentRole->permissions()->count() . ' permissions');
        }

        // =====================================================
        // PARENT - Can only view their children's information
        // =====================================================
        if ($parentRole) {
            $parentPermissions = Permission::whereIn('slug', [
                'students.view',
            ])->pluck('id');
            
            $parentRole->permissions()->sync($parentPermissions);
            $this->command->info('Parent: ' . $parentRole->permissions()->count() . ' permissions');
        }

        $this->command->info('');
        $this->command->info('All permissions assigned to roles successfully!');
    }
}