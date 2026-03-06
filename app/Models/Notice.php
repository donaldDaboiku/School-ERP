<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Notice extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'school_id',
        'title',
        'content',
        'type',
        'priority',
        'target_audience',
        'target_classes',
        'published_by',
        'published_at',
        'expires_at',
        'is_published',
        'send_notification',
        'notification_sent_at',
        'views_count',
        'attachments',
    ];

    protected $casts = [
        'published_at' => 'datetime',
        'expires_at' => 'datetime',
        'notification_sent_at' => 'datetime',
        'is_published' => 'boolean',
        'send_notification' => 'boolean',
        'target_classes' => 'array',
        'attachments' => 'array',
        'views_count' => 'integer',
    ];

    // Relationships
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    // Accessors
    public function getIsActiveAttribute(): bool
    {
        if (!$this->is_published) return false;
        
        if ($this->expires_at && $this->expires_at < now()) {
            return false;
        }
        
        return true;
    }

    public function getIsExpiredAttribute(): bool
    {
        return $this->expires_at && $this->expires_at < now();
    }

    public function getShortContentAttribute(): string
    {
        return strlen($this->content) > 100 
            ? substr($this->content, 0, 100) . '...' 
            : $this->content;
    }

    public function getPriorityColorAttribute(): string
    {
        return match($this->priority) {
            'urgent' => 'danger',
            'high' => 'warning',
            'medium' => 'info',
            'low' => 'success',
            default => 'secondary',
        };
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('is_published', true)
            ->where(function ($q) {
                $q->whereNull('expires_at')
                  ->orWhere('expires_at', '>', now());
            });
    }

    public function scopePublished($query)
    {
        return $query->where('is_published', true);
    }

    public function scopeForAudience($query, $audience)
    {
        return $query->where('target_audience', $audience)
            ->orWhere('target_audience', 'all');
    }

    public function scopeForClass($query, $classId)
    {
        return $query->whereJsonContains('target_classes', $classId)
            ->orWhere('target_audience', 'all');
    }

    public function scopeUrgent($query)
    {
        return $query->where('priority', 'urgent');
    }

    public function scopeRecent($query, $days = 7)
    {
        return $query->where('published_at', '>=', now()->subDays($days));
    }

    // Methods
    public function incrementViews(): void
    {
        $this->increment('views_count');
    }

    public function publish(): void
    {
        $this->update([
            'is_published' => true,
            'published_at' => now(),
        ]);
    }

    public function markAsNotified(): void
    {
        $this->update([
            'notification_sent_at' => now(),
        ]);
    }
}