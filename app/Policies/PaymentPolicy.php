<?php

namespace App\Policies;

use App\Models\Payment;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class PaymentPolicy
{
    use HandlesAuthorization;

    private function isSuperAdmin(User $user): bool
    {
        return $user->user_type === 'super_admin';
    }

    private function sameSchool(User $user, Payment $payment): bool
    {
        return $user->school_id !== null && $user->school_id === $payment->school_id;
    }

    public function viewAny(User $user): bool
    {
        if ($this->isSuperAdmin($user)) return true;

        return in_array($user->user_type, ['admin', 'principal', 'accountant', 'teacher', 'parent', 'student'], true);
    }

    public function view(User $user, Payment $payment): bool
    {
        if ($this->isSuperAdmin($user)) return true;

        if (!$this->sameSchool($user, $payment)) return false;

        if (in_array($user->user_type, ['admin', 'principal', 'accountant'], true)) {
            return true;
        }

        if ($user->user_type === 'teacher') {
            if (!$payment->student || !$user->teacher) {
                return false;
            }

            return $user->teacher->classes()
                ->where('classes.id', $payment->student->class_id)
                ->exists();
        }

        if ($user->user_type === 'student') {
            return $user->student && $user->student->id === $payment->student_id;
        }

        if ($user->user_type === 'parent') {
            return $user->students()->where('students.id', $payment->student_id)->exists();
        }

        return false;
    }

    public function create(User $user): bool
    {
        if ($this->isSuperAdmin($user)) return true;

        return in_array($user->user_type, ['admin', 'principal', 'accountant'], true);
    }

    public function update(User $user, Payment $payment): bool
    {
        if ($this->isSuperAdmin($user)) return true;

        if (!$this->sameSchool($user, $payment)) return false;

        return in_array($user->user_type, ['admin', 'principal', 'accountant'], true);
    }

    public function delete(User $user, Payment $payment): bool
    {
        if ($this->isSuperAdmin($user)) return true;

        if (!$this->sameSchool($user, $payment)) return false;

        return in_array($user->user_type, ['admin', 'principal'], true);
    }

    public function restore(User $user, Payment $payment): bool
    {
        return $this->delete($user, $payment);
    }

    public function forceDelete(User $user, Payment $payment): bool
    {
        if ($this->isSuperAdmin($user)) return true;

        return false;
    }
}
