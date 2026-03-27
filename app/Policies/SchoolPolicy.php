<?php

namespace App\Policies;

use App\Models\School;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class SchoolPolicy
{
    use HandlesAuthorization;

    private function isSuperAdmin(User $user): bool
    {
        return $user->user_type === 'super_admin';
    }

    private function sameSchool(User $user, School $school): bool
    {
        return $user->school_id !== null && $user->school_id === $school->id;
    }

    public function viewAny(User $user): bool
    {
        return $this->isSuperAdmin($user);
    }

    public function view(User $user, School $school): bool
    {
        if ($this->isSuperAdmin($user)) return true;

        return $this->sameSchool($user, $school);
    }

    public function create(User $user): bool
    {
        return $this->isSuperAdmin($user);
    }

    public function update(User $user, School $school): bool
    {
        if ($this->isSuperAdmin($user)) return true;

        if (!$this->sameSchool($user, $school)) return false;

        return in_array($user->user_type, ['admin', 'principal'], true);
    }

    public function delete(User $user, School $school): bool
    {
        return $this->isSuperAdmin($user);
    }

    public function restore(User $user, School $school): bool
    {
        return $this->delete($user, $school);
    }

    public function forceDelete(User $user, School $school): bool
    {
        return $this->isSuperAdmin($user);
    }
}
