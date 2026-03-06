<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Role extends Model
{
    use HasFactory;

    protected $table = 'roles';

    
    protected $fillable = [
        'school_id',
        'name',
        'slug',
        'description',
        'is_active',
    ];

    protected $caasts = [
        'is_active' => 'boolean',
    ];

    public function school()
    {
        return $this->belongsTo(School::class, 'school_id', 'id');
    }

    public function users()
    {
        return $this->belongsToMany(User::class, 'role_user', 'role_id', 'user_id')->withTimestamps();
    }

    public function permission()       
    {
        return $this->belongsToMany(Permission::class, 'permission_role', 'role_id', 'permission_id')->withTimestamps();
    }

    public function permissions()
{
    return $this->belongsToMany(Permission::class, 'permission_role', 'role_id', 'permission_id');
}

    /**
     * Give a role permission
     */
    public function assignPermission($permission)
    {
        $permissionId = is_object($permission) ? $permission->id : $permission;
        $this->permission()->syncWithoutDetaching([$permissionId]);
        return $this;
    }
    /**
     * Remove permission from a role
     */
    public function removePermission($permission)
    {
        $permissionId = is_object($permission) ? $permission->id : $permission;
        $this->permission()->detach([$permissionId]);
        return $this;
    }
    /**
     * Check if a role has a permission
     */
    public function hasPermission($permission)
    {
        $permissionId = is_object($permission) ? $permission->id : $permission;
        return $this->permission()->where('id', $permissionId)->exists();
    }
    /** 
     * Check if a role has all permissions
     */
    public function hasAllPermissions($permission)
    {
        $slug = is_object($permission) ? $permission->slug : $permission;
        return $this->permission()->where('slug', $slug)->exists();
    }
    /**
     * Sync all permissions for this role
     */
    public function syncPermissions($permissions)
    {
        // $permissionIds = is_array($permissions) ? $permissions : [$permissions];
        $this->permission()->sync($permissions);
        return $this;
    }
    /**
     * Get all permissions for this role
     */
    public function getAllPermissions()
    {
        return $this->permission()->get();
    }

    // ============================================
    // SCOPES (USEFUL QUERIES)
    // ============================================

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForSchool($query, $schoolId)
    {
        return $query->where('school_id', $schoolId);
    }
}
