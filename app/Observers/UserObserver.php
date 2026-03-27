<?php
namespace App\Observers;

use App\Models\User;
use App\Events\UserCreated;
use App\Events\UserUpdated;
use App\Events\UserDeleted;
use App\Events\UserPasswordChanged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\Token;

class UserObserver
{
    /**
     * Handle events after all transactions are committed.
     *
     * @var bool
     */
    public $afterCommit = true;

    /**
     * Handle the User "creating" event.
     *
     * @param  \App\Models\User  $user
     * @return void
     */
    public function creating(User $user)
    {
        try {
            // Generate unique username if not provided
            if (empty($user->username)) {
                $user->username = $this->generateUniqueUsername($user);
            }

            // Generate email verification token
            if ($user->email_verified_at === null && config('auth.email_verification.enabled')) {
                $user->email_verification_token = Str::random(60);
                $user->email_verification_token_expires_at = now()->addHours(24);
            }

            // Set default status
            if (empty($user->status)) {
                $user->status = 'active';
            }

            // Generate API token for system users
            if ($user->is_system_user && empty($user->api_token)) {
                $user->api_token = Str::random(80);
            }

            // Log user creation attempt
            Log::info('User creating', [
                'email' => $user->email,
                'username' => $user->username,
                'ip' => request()->ip(),
                'user_agent' => request()->userAgent()
            ]);

        } catch (\Exception $e) {
            Log::error('User creation failed', [
                'error' => $e->getMessage(),
                'user_data' => $user->toArray()
            ]);
            throw $e;
        }
    }

    /**
     * Handle the User "created" event.
     *
     * @param  \App\Models\User  $user
     * @return void
     */
    public function created(User $user)
    {
        try {
            // Clear user cache
            Cache::tags(['users'])->flush();

            // Dispatch user created event
            event(new UserCreated($user, Auth::id()));
            event(new \App\Events\UserRegistered($user));

            // Send welcome email (queued)
            if (config('mail.enabled')) {
                \App\Jobs\SendWelcomeEmail::dispatch($user)->onQueue('emails');
            }

            // Create user profile if doesn't exist
            if (!$user->profile) {
                $user->profile()->create([
                    'timezone' => config('app.timezone'),
                    'locale' => config('app.locale'),
                    'notifications_enabled' => true,
                    'two_factor_enabled' => false
                ]);
            }

            // Log successful creation
            Log::info('User created successfully', [
                'user_id' => $user->id,
                'email' => $user->email,
                'role' => $user->getRoleNames()->first()
            ]);

        } catch (\Exception $e) {
            Log::error('User created event failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * Handle the User "updating" event.
     *
     * @param  \App\Models\User  $user
     * @return void
     */
    public function updating(User $user)
    {
        try {
            // Detect password change
            if ($user->isDirty('password')) {
                $user->password_changed_at = now();
                $user->password_change_required = false;
                
                // Store old password hash for security audit
                if (config('security.password_change_logging')) {
                    $oldPasswordHash = $user->getOriginal('password');
                    \App\Models\PasswordHistory::create([
                        'user_id' => $user->id,
                        'password_hash' => $oldPasswordHash,
                        'changed_at' => now(),
                        'changed_by' => Auth::id() ?? $user->id
                    ]);
                }
            }

            // Detect email change
            if ($user->isDirty('email')) {
                $user->email_verified_at = null;
                $user->email_verification_token = Str::random(60);
                $user->email_verification_token_expires_at = now()->addHours(24);
            }

            // Detect status change
            if ($user->isDirty('status')) {
                $oldStatus = $user->getOriginal('status');
                $newStatus = $user->status;
                
                Log::warning('User status changed', [
                    'user_id' => $user->id,
                    'from' => $oldStatus,
                    'to' => $newStatus,
                    'changed_by' => Auth::id() ?? 'system'
                ]);
            }

            // Update timestamps for security fields
            if ($user->isDirty('last_login_at') || $user->isDirty('failed_login_attempts')) {
                $user->security_updated_at = now();
            }

        } catch (\Exception $e) {
            Log::error('User updating failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Handle the User "updated" event.
     *
     * @param  \App\Models\User  $user
     * @return void
     */
    public function updated(User $user)
    {
        try {
            // Clear user cache
            Cache::forget("user_{$user->id}");
            Cache::forget("user_email_{$user->email}");
            Cache::tags(['users'])->flush();

            // Dispatch user updated event
            event(new UserUpdated($user));

            // Dispatch password changed event if password was changed
            if ($user->wasChanged('password')) {
                event(new UserPasswordChanged($user));
                
                // Send password changed notification
                if (config('mail.enabled')) {
                    \App\Jobs\SendPasswordChangedNotification::dispatch($user)->onQueue('emails');
                }

                // Invalidate all active sessions except current
                if (config('security.invalidate_sessions_on_password_change')) {
                    $currentToken = $user->currentAccessToken();
                    $currentTokenId = null;

                    if ($currentToken instanceof Token) {
                        $currentTokenId = $currentToken->getKey();
                    } elseif ($currentToken && isset($currentToken->id)) {
                        $currentTokenId = $currentToken->id;
                    }

                    if ($currentTokenId !== null) {
                        $user->tokens()->where('id', '!=', $currentTokenId)->delete();
                    } else {
                        $user->tokens()->delete();
                    }
                }
            }

            // Send email verification if email was changed
            if ($user->wasChanged('email')) {
                \App\Jobs\SendEmailVerificationNotification::dispatch($user)->onQueue('emails');
            }

            // Log the update
            $changes = $user->getChanges();
            unset($changes['password']); // Don't log password changes
            
            if (!empty($changes)) {
                Log::info('User updated', [
                    'user_id' => $user->id,
                    'changes' => $changes,
                    'updated_by' => Auth::id() ?? 'system'
                ]);
            }

        } catch (\Exception $e) {
            Log::error('User updated event failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Handle the User "deleting" event.
     *
     * @param  \App\Models\User  $user
     * @return void
     */
    public function deleting(User $user)
    {
        try {
            // Prevent deletion of system users
            if ($user->is_system_user && !app()->runningInConsole()) {
                throw new \Exception('Cannot delete system user');
            }

            // Prevent deletion of users with active dependencies
            $this->validateDeletion($user);

            // Create deletion record for audit trail
            \App\Models\UserDeletionLog::create([
                'user_id' => $user->id,
                'deleted_by' => Auth::id(),
                'reason' => request()->input('deletion_reason'),
                'data_backup' => $this->backupUserData($user)
            ]);

            // Log deletion attempt
            Log::warning('User deletion initiated', [
                'user_id' => $user->id,
                'email' => $user->email,
                'deleted_by' => Auth::id(),
                'ip' => request()->ip()
            ]);

        } catch (\Exception $e) {
            Log::error('User deletion validation failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Handle the User "deleted" event.
     *
     * @param  \App\Models\User  $user
     * @return void
     */
    public function deleted(User $user)
    {
        try {
            // Clear all user-related cache
            Cache::forget("user_{$user->id}");
            Cache::forget("user_email_{$user->email}");
            Cache::tags(['users', 'user_' . $user->id])->flush();

            // Dispatch user deleted event
            event(new UserDeleted($user, Auth::id(), request()->input('deletion_reason')));

            // Archive user data if configured
            if (config('users.archive_deleted_users')) {
                $this->archiveUserData($user);
            }

            // Send deletion notification to admins
            \App\Jobs\NotifyAdminsOfUserDeletion::dispatch($user)->onQueue('notifications');

            // Log successful deletion
            Log::critical('User permanently deleted', [
                'user_id' => $user->id,
                'email' => $user->email,
                'deleted_by' => Auth::id(),
                'roles' => $user->getRoleNames()->toArray()
            ]);

        } catch (\Exception $e) {
            Log::error('User deletion cleanup failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Handle the User "restoring" event.
     *
     * @param  \App\Models\User  $user
     * @return void
     */
    public function restoring(User $user)
    {
        try {
            // Check if email is still unique
            if (User::where('email', $user->email)->where('id', '!=', $user->id)->exists()) {
                throw new \Exception("Email {$user->email} is already taken");
            }

            // Check if username is still unique
            if ($user->username && User::where('username', $user->username)->where('id', '!=', $user->id)->exists()) {
                $user->username = $this->generateUniqueUsername($user);
            }

            // Reset user status
            $user->status = 'active';
            $user->restored_at = now();
            $user->restored_by = Auth::id();

            Log::info('User restoring', [
                'user_id' => $user->id,
                'email' => $user->email
            ]);

        } catch (\Exception $e) {
            Log::error('User restoration failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Handle the User "restored" event.
     *
     * @param  \App\Models\User  $user
     * @return void
     */
    public function restored(User $user)
    {
        try {
            // Clear cache
            Cache::tags(['users'])->flush();

            // Dispatch user restored event
            event(new UserUpdated($user));

            // Send restoration notification
            \App\Jobs\SendAccountRestoredNotification::dispatch($user)->onQueue('emails');

            // Log restoration
            Log::info('User restored', [
                'user_id' => $user->id,
                'email' => $user->email,
                'restored_by' => Auth::id()
            ]);

        } catch (\Exception $e) {
            Log::error('User restoration cleanup failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Handle the User "force deleted" event.
     *
     * @param  \App\Models\User  $user
     * @return void
     */
    public function forceDeleted(User $user)
    {
        try {
            // Permanent cleanup of user data
            $this->permanentlyDeleteUserData($user);

            // Final cache clearance
            Cache::tags(['users', 'user_' . $user->id])->flush();

            // Log permanent deletion
            Log::critical('User force deleted from database', [
                'user_id' => $user->id,
                'email' => $user->email,
                'deleted_by' => Auth::id()
            ]);

        } catch (\Exception $e) {
            Log::error('User force deletion cleanup failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Generate a unique username for the user.
     *
     * @param  \App\Models\User  $user
     * @return string
     */
    private function generateUniqueUsername(User $user)
    {
        $baseUsername = Str::slug($user->name);
        $username = $baseUsername;
        $counter = 1;

        while (User::where('username', $username)->exists()) {
            $username = $baseUsername . $counter;
            $counter++;
            
            if ($counter > 100) {
                $username = $baseUsername . '_' . Str::random(6);
                break;
            }
        }

        return $username;
    }

    /**
     * Validate if user can be deleted.
     *
     * @param  \App\Models\User  $user
     * @return void
     * @throws \Exception
     */
    private function validateDeletion(User $user)
    {
        // Check if user has active sessions
        if ($user->tokens()->where('revoked', false)->exists()) {
            throw new \Exception('User has active sessions');
        }

        // Check if user is the last admin
        if ($user->hasRole('admin') || $user->hasRole('super-admin')) {
            $adminCount = User::role(['admin', 'super-admin'])->count();
            if ($adminCount <= 1) {
                throw new \Exception('Cannot delete the last administrator');
            }
        }

        // Check for pending financial transactions
        if ($user->financialTransactions()->where('status', 'pending')->exists()) {
            throw new \Exception('User has pending financial transactions');
        }

        // Check for active responsibilities (if teacher)
        if ($user->teacher && $user->teacher->classes()->exists()) {
            throw new \Exception('Teacher has active class assignments');
        }
    }

    /**
     * Backup user data before deletion.
     *
     * @param  \App\Models\User  $user
     * @return array
     */
    private function backupUserData(User $user)
    {
        return [
            'user' => $user->toArray(),
            'profile' => $user->profile ? $user->profile->toArray() : null,
            'roles' => $user->roles->pluck('name')->toArray(),
            'permissions' => $user->permissions->pluck('name')->toArray(),
            'created_at' => $user->created_at,
            'last_login' => $user->last_login_at,
            'activity_count' => $user->activityLogs()->count(),
            'backup_timestamp' => now()
        ];
    }

    /**
     * Archive user data for compliance.
     *
     * @param  \App\Models\User  $user
     * @return void
     */
    private function archiveUserData(User $user)
    {
        try {
            $archiveData = [
                'user_id' => $user->id,
                'email' => $user->email,
                'name' => $user->name,
                'roles' => $user->getRoleNames()->toArray(),
                'data' => $this->backupUserData($user),
                'deleted_at' => now(),
                'deleted_by' => Auth::id()
            ];

            \App\Models\UserArchive::create($archiveData);

        } catch (\Exception $e) {
            Log::error('User data archiving failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Permanently delete all user-related data.
     *
     * @param  \App\Models\User  $user
     * @return void
     */
    private function permanentlyDeleteUserData(User $user)
    {
        // Delete personal access tokens
        $user->tokens()->delete();

        // Delete OAuth tokens if using Passport
        if (class_exists('Laravel\Passport\Token')) {
            Token::where('user_id', $user->id)->delete();
            \Laravel\Passport\RefreshToken::where('access_token_id', function ($query) use ($user) {
                $query->select('id')->from('oauth_access_tokens')->where('user_id', $user->id);
            })->delete();
        }

        // Delete notifications
        $user->notifications()->delete();
        $user->readNotifications()->delete();

        // Delete activity logs
        $user->activityLogs()->delete();

        // Delete profile
        if ($user->profile) {
            $user->profile()->delete();
        }

        // Detach roles and permissions
        $user->roles()->detach();
        $user->permissions()->detach();

        // Delete password history
        \App\Models\PasswordHistory::where('user_id', $user->id)->delete();
    }

    /**
     * Store password history
     * 
     * @param \App\Models\User $user
     * @return void
     */
    private function storePasswordHistory(User $user)
    {
        try{
            DB::table('password_history')->insert([
                'user_id' => $user->id,
                'password_hash' => $user->getOriginal('password'),
                'changed_at' => now(),
                'changed_by' => Auth::id() ?? $user->id,
            ]);
        }catch(\Exception $e){
            Log::error('Storing password history failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Handle password change
     * 
     * @param \App\Models\User $user
     * @return void
     *  
     */

    private function handlePasswordChange(User $user)
    {
        try{
            //Invalidate all active sessions except current
            if(config('app.invalidate_sessions_on_password_change', true)){

                if(method_exists($user, 'tokens')){
                    $currentToken = $user->currentAccessToken();
                    $currentTokenId = null;

                    if ($currentToken instanceof Token) {
                        $currentTokenId = $currentToken->getKey();
                    } elseif ($currentToken && isset($currentToken->id)) {
                        $currentTokenId = $currentToken->id;
                    }

                    if ($currentTokenId !== null) {
                        $user->tokens()->where('id', '!=', $currentTokenId)->delete();
                    } else {
                        $user->tokens()->delete();
                    }
                }
            }
            //Send password changed notification
            $this->sendPasswordChangedNotification($user);
        } catch(\Exception $e){
            Log::error('Handling password change failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);
        }
    }
    private function sendWelcomeEmail(User $user)
    {
        try{
            // In production, you would dispatch a job here
            // For now, we'll log it
            log::info('Welcome email should be sent to', [
                'user_id' => $user->id,
                'email' => $user->email
            ]);
            
        } catch(\Exception $e){
            Log::error('Sending welcome email failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);
        }

    }
    /**
     * Send password changed notification
     * 
     * @param \App\Models\User $user
     * @return void
     */
    
private function sendPasswordChangedNotification(User $user)
    {
        try{
            Log::info('Password changed notification should be sent to',[
                'user_id'=> $user->id,
                'email' => $user->email
            ]);
        } catch(\Exception $e){
            Log::error('Password changed notification failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);
        }

    }

    /**
     * Send email verification.
     *
     * @param  \App\Models\User  $user
     * @return void
     * 
     */
    private function sendEmailVerification(User $user)
    {
        try{
            Log::info('Email verification should be sent to',[
                'user_id'=> $user->id,
                'email' => $user->email
            ]);
        } catch(\Exception $e){
            Log::error('Sending email verification failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);
        }
    }
    /**
     * Notify admins of user deletion.
     *
     * @param  \App\Models\User  $user
     * @return void
     */

    private function notifyAdminsOfDeletion(User $user)
    {
        try{
            Log::info('Admins should be notified of user deletion',[
                'user_id'=> $user->id,
                'email' => $user->email
            ]);
        } catch(\Exception $e){
            Log::error('Notifying admins of user deletion failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);
        }
    }   
    /**
     * Send account restored notification
     *
     * @param  \App\Models\User  $user
     * @return void
     */

    private function sendAccountRestoredNotification(User $user)
    {
        try{
            Log::info('Account restored notification should be sent to',[
                'user_id'=> $user->id,
                'email' => $user->email
            ]);
        } catch(\Exception $e){
            Log::error('Sending account restored notification failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);
        }
    }
}

