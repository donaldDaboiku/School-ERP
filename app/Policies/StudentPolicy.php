<?php

namespace App\Policies;

use App\Models\Student;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class StudentPolicy
{
    use HandlesAuthorization;

    private function isSuperAdmin(User $user): bool
    {
        return $user->user_type === 'super_admin';
    }

    private function sameSchool(User $user, Student $student): bool
    {
        return $user->school_id !== null && $user->school_id === $student->school_id;
    }

    public function viewAny(User $user): bool
    {
        if ($this->isSuperAdmin($user)) return true;

        return in_array($user->user_type, ['admin', 'principal', 'teacher'], true);
    }

    public function view(User $user, Student $student): bool
    {
        if ($this->isSuperAdmin($user)) return true;

        if (!$this->sameSchool($user, $student)) return false;

        if (in_array($user->user_type, ['admin', 'principal', 'teacher'], true)) {
            if ($user->user_type === 'teacher') {
                return $user->teacher
                    ? $user->teacher->classes()->where('classes.id', $student->class_id)->exists()
                    : false;
            }

            return true;
        }

        if ($user->user_type === 'parent') {
            return $user->students()->where('students.id', $student->id)->exists();
        }

        if ($user->user_type === 'student') {
            return $user->student && $user->student->id === $student->id;
        }

        return false;
    }

    public function create(User $user): bool
    {
        if ($this->isSuperAdmin($user)) return true;

        return in_array($user->user_type, ['admin', 'principal'], true);
    }

    public function update(User $user, Student $student): bool
    {
        if ($this->isSuperAdmin($user)) return true;

        if (!$this->sameSchool($user, $student)) return false;

        if (in_array($user->user_type, ['admin', 'principal'], true)) {
            return true;
        }

        if ($user->user_type === 'teacher') {
            return $user->teacher
                ? $user->teacher->classes()->where('classes.id', $student->class_id)->exists()
                : false;
        }

        return false;
    }

    public function delete(User $user, Student $student): bool
    {
        if ($this->isSuperAdmin($user)) return true;

        if (!$this->sameSchool($user, $student)) return false;

        return in_array($user->user_type, ['admin', 'principal'], true);
    }

    public function restore(User $user, Student $student): bool
    {
        return $this->delete($user, $student);
    }

    public function forceDelete(User $user, Student $student): bool
    {
        return $this->isSuperAdmin($user);
    }
}
