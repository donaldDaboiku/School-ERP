<?php

namespace App\Policies;

use App\Models\Teacher;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class TeacherPolicy
{
    use HandlesAuthorization;

    private function isSuperAdmin(User $user): bool
    {
        return $user->user_type === 'super_admin';
    }

    private function sameSchool(User $user, Teacher $teacher): bool
    {
        return $user->school_id !== null && $user->school_id === $teacher->school_id;
    }

    public function viewAny(User $user): bool
    {
        if ($this->isSuperAdmin($user)) return true;

        return in_array($user->user_type, ['admin', 'principal'], true);
    }

    public function view(User $user, Teacher $teacher): bool
    {
        if ($this->isSuperAdmin($user)) return true;

        if (!$this->sameSchool($user, $teacher)) return false;

        if (in_array($user->user_type, ['admin', 'principal'], true)) {
            return true;
        }

        if ($user->user_type === 'teacher') {
            return $user->teacher && $user->teacher->id === $teacher->id;
        }

        return false;
    }

    public function create(User $user): bool
    {
        if ($this->isSuperAdmin($user)) return true;

        return in_array($user->user_type, ['admin', 'principal'], true);
    }

    public function update(User $user, Teacher $teacher): bool
    {
        if ($this->isSuperAdmin($user)) return true;

        if (!$this->sameSchool($user, $teacher)) return false;

        if (in_array($user->user_type, ['admin', 'principal'], true)) {
            return true;
        }

        if ($user->user_type === 'teacher') {
            return $user->teacher && $user->teacher->id === $teacher->id;
        }

        return false;
    }

    public function delete(User $user, Teacher $teacher): bool
    {
        if ($this->isSuperAdmin($user)) return true;

        if (!$this->sameSchool($user, $teacher)) return false;

        return in_array($user->user_type, ['admin', 'principal'], true);
    }

    public function restore(User $user, Teacher $teacher): bool
    {
        return $this->delete($user, $teacher);
    }

    public function forceDelete(User $user, Teacher $teacher): bool
    {
        return $this->isSuperAdmin($user);
    }
}
