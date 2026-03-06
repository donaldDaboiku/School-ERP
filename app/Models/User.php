<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
// use Illuminate\Database\Eloquent\Factories\HasApiTokens;   
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Passport\HasApiTokens;

/**
 * @property int $id
 * @property string|null $username
 * @property bool $is_system_user
 * @property string $status
 * @property \Carbon\Carbon $created_at
 * @property Profile|null $profile
 * @property \Illuminate\Support\Collection $roles
 * @property \Illuminate\Support\Collection $permissions
 */
class User extends Authenticatable
{
    use  HasFactory, Notifiable,HasApiTokens, SoftDeletes;

    protected $fillable = [
        'name',
        'first_name',
        'last_name',
        'middle_name',
        'email',
        'password',
        'school_id',
        'user_type',
        'status',
        'phone',
        'avatar',
        'gender',
        'date_of_birth',
        'address',
        'username',
        'id_number',
        'employee_id',
        'student_id',
        'last_login_at',
        'last_login_ip',
        'password_changed_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'last_login_at' => 'datetime',
        'password_changed_at' => 'datetime',
        'date_of_birth' => 'date',
    ];


 // ---------------- USER RELATED PROFILES ----------------

    /**
     * A User may have one associated Student profile.
     */
    public function student(): HasOne
    {
        return $this->hasOne(Student::class, 'user_id', 'id');
    }

    /**
     * A User may have one associated Teacher profile.
     */
    public function teacher(): HasOne
    {
        return $this->hasOne(Teacher::class, 'user_id', 'id');
    }

    /**
     * A User may have one associated Parent profile.
     */
    public function parent(): HasOne
    {
        return $this->hasOne(Parents::class, 'user_id', 'id'); // Use Parents::class not Parent
    }

    // ---------------- SCHOOL ----------------

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class, 'school_id', 'id');
    }

    // ---------------- ATTENDANCES, RESULTS, NOTICES, PAYMENTS ----------------

    public function recordedAttendances(): HasMany
    {
        return $this->hasMany(Attendance::class, 'recorded_by', 'id');
    }

    public function gradedResults(): HasMany
    {
        return $this->hasMany(Result::class, 'graded_by', 'id');
    }

    public function publishedNotices(): HasMany
    {
        return $this->hasMany(Notice::class, 'published_by', 'id');
    }

    public function receivedPayments(): HasMany
    {
        return $this->hasMany(Payment::class, 'received_by', 'id');
    }

    public function passwordHistories(): HasMany
    {
        return $this->hasMany(PasswordHistory::class, 'user_id', 'id');
    }

    // ---------------- CLASS & SUBJECTS ----------------

    public function level(): BelongsTo
    {
        return $this->belongsTo(ClassLevel::class, 'class_level_id', 'id');
    }

    public function sections(): HasMany
    {
        return $this->hasMany(Section::class, 'user_id', 'id');
    }

    public function subjects(): BelongsToMany
    {
        return $this->belongsToMany(Subject::class, 'class_subject', 'user_id', 'subject_id');
    }

    // ---------------- PARENTS & STUDENTS ----------------

    public function students(): BelongsToMany
    {
        return $this->belongsToMany(Student::class, 'parent_student', 'parent_user_id', 'student_id');
    }

    public function parents(): BelongsToMany
    {
        return $this->belongsToMany(Parents::class, 'parent_student', 'student_user_id', 'parent_id');
    }

    public function emergencyContacts(): HasMany
    {
        return $this->hasMany(StudentEmergencyContact::class, 'user_id', 'id');
    }

    public function pickupAuthorizations(): HasMany
    {
        return $this->hasMany(PickupAuthorization::class, 'user_id', 'id');
    }

    public function parentTeacherMeetings(): HasMany
    {
        return $this->hasMany(ParentTeacherMeeting::class, 'user_id', 'id');
    }

    public function communications(): HasMany
    {
        return $this->hasMany(ParentCommunicationLog::class, 'user_id', 'id');
    }

    // ---------------- PROFILE, ROLES & PERMISSIONS ----------------

    public function profile(): HasOne
    {
        return $this->hasOne(Profile::class, 'user_id', 'id');
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_user', 'user_id', 'role_id')->withTimestamps();
    }

    public function permission(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'permission_user', 'user_id', 'permission_id');
    }


    public function hasPermission($permission)
    {
        if ($this->user_type === 'super_admin') return true;

        return $this->roles()
            ->whereHas('permissions', function ($q) use ($permission) {
                $q->where('slug', $permission);
            })->exists();
    }

    public function hasRole($role)
    {
        return $this->roles()->where('slug', $role)->exists();
    }

    public function assignRole($role)
    {
        $roleId = is_object($role) ? $role->id : $role;
        $this->roles()->syncWithoutDetaching([$roleId]);
        return $this;
    }
}
