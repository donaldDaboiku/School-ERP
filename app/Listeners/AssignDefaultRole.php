<?php

namespace App\Listeners;

use App\Events\UserRegistered;

class AssignDefaultRole
{
    /**
     * Create the event listener.
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     */
    public function handle(UserRegistered $event): void
    {
        $user = $event->user ?? null;
        if (!$user) {
            \Illuminate\Support\Facades\Log::warning('AssignDefaultRole: missing user');
            return;
        }

        $slug = $user->user_type === 'super_admin' ? 'super-admin' : $user->user_type;
        $role = \App\Models\Role::where('slug', $slug)->first()
            ?? \App\Models\Role::where('name', $slug)->first();

        if ($role && !$user->hasRole($role->slug ?? $role->name)) {
            $user->assignRole($role->id);
        }
    }
}
