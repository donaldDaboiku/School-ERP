<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class Parents extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'parents';

    protected $fillable = [
        'user_id', // Link to users table
        'name',
        'relationship',
        'phone',
        'email',
        'password',
        'address',
        'occupation',
        'company',
        'monthly_income',
        'education_level',
        'is_primary',
        'is_emergency_contact',
        'can_pickup',
        'communication_preference',
        'status',
        'profile_picture',
        'notes',
        'last_login_at'
    ];

    protected $hidden = ['password', 'remember_token'];

    protected $casts = [
        'is_primary' => 'boolean',
        'is_emergency_contact' => 'boolean',
        'can_pickup' => 'boolean',
        'monthly_income' => 'decimal:2',
        'last_login_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime'
    ];

    // ---------------- RELATIONSHIPS ----------------

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function students()
    {
        return $this->belongsToMany(Student::class, 'parent_student', 'parent_id', 'student_id')
                    ->withPivot('relationship', 'is_primary')
                    ->withTimestamps();
    }

    public function communicationLogs()
    {
        return $this->hasMany(ParentCommunicationLog::class, 'parent_id');
    }

    public function meetings()
    {
        return $this->hasMany(ParentTeacherMeeting::class, 'parent_id');
    }

    public function pickupAuthorizations()
    {
        return $this->hasMany(PickupAuthorization::class, 'authorized_by');
    }

    public function emergencyContacts()
    {
        return $this->hasMany(StudentEmergencyContact::class, 'parent_id');
    }

    // ---------------- ACCESSORS ----------------

    public function getFullNameAttribute()
    {
        return $this->name;
    }

    public function getRelationshipDisplayAttribute()
    {
        $relationships = [
            'father' => 'Father',
            'mother' => 'Mother',
            'guardian' => 'Guardian',
            'grandfather' => 'Grandfather',
            'grandmother' => 'Grandmother',
            'uncle' => 'Uncle',
            'aunt' => 'Aunt',
            'sibling' => 'Sibling',
            'other' => 'Other'
        ];

        return $relationships[$this->relationship] ?? ucfirst($this->relationship);
    }

    // ---------------- SCOPES ----------------

    public function scopePrimary($query)
    {
        return $query->where('is_primary', true);
    }

    public function scopeEmergencyContacts($query)
    {
        return $query->where('is_emergency_contact', true);
    }

    public function scopeCanPickup($query)
    {
        return $query->where('can_pickup', true);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    // ---------------- METHODS ----------------

    public function sendNotification($title, $message, $type = 'general')
    {
        ParentCommunicationLog::create([
            'parent_id' => $this->id,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'sent_via' => $this->communication_preference ?? 'email',
            'sent_by' => Auth::id() ?? null,
            'status' => 'sent'
        ]);

        return true;
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($parent) {
            if (empty($parent->password)) {
                $parent->password = bcrypt(Str::random(10));
            }
        });

        static::deleting(function ($parent) {
            $parent->communicationLogs()->delete();
            $parent->meetings()->delete();
            $parent->pickupAuthorizations()->delete();
        });
    }
}
