<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;    

class ClassroomReservation extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'classroom_reservations';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'classroom_id',
        'requester_id',
        'purpose',
        'event_type',
        'event_title',
        'event_description',
        'reservation_date',
        'start_time',
        'end_time',
        'recurring_type',
        'recurring_days',
        'recurring_end_date',
        'expected_attendees',
        'setup_requirements',
        'equipment_needed',
        'special_requirements',
        'approved_by',
        'approved_at',
        'rejected_by',
        'rejected_at',
        'rejection_reason',
        'cancelled_by',
        'cancelled_at',
        'cancellation_reason',
        'checked_in_at',
        'checked_out_at',
        'actual_attendees',
        'feedback',
        'rating',
        'status',
        'notes',
        'created_by',
        'updated_by'
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'reservation_date' => 'date',
        'start_time' => 'datetime:H:i',
        'end_time' => 'datetime:H:i',
        'recurring_end_date' => 'date',
        'recurring_days' => 'json',
        'expected_attendees' => 'integer',
        'actual_attendees' => 'integer',
        'setup_requirements' => 'json',
        'equipment_needed' => 'json',
        'special_requirements' => 'json',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'checked_in_at' => 'datetime',
        'checked_out_at' => 'datetime',
        'rating' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime'
    ];

    /**
     * The attributes that should be appended.
     *
     * @var array<string>
     */
    protected $appends = [
        'status_display',
        'event_type_display',
        'recurring_type_display',
        'duration_hours',
        'time_slot',
        'is_recurring',
        'is_active',
        'is_past',
        'is_ongoing',
        'is_upcoming',
        'conflicts',
        'requester_name',
        'approver_name',
        'rejector_name',
        'canceller_name',
        'full_event_title',
        'setup_requirements_list',
        'equipment_needed_list'
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array
     */
    protected $with = ['classroom', 'requester', 'approver', 'rejector', 'canceller', 'creator', 'updater'];

    /**
     * Boot the model.
     *
     * @return void
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($reservation) {
            // Set default status
            if (empty($reservation->status)) {
                $reservation->status = 'pending';
            }
            
            // Set created_by if not set
            if (empty($reservation->created_by) && Auth::check()) {
                $reservation->created_by = Auth::id();
            }
            
            // Validate reservation
            self::validateReservation($reservation);
            
            // Set requester if not set
            if (empty($reservation->requester_id) && Auth::check()) {
                $reservation->requester_id = Auth::id();
            }
            
            // Set event title if not provided
            if (empty($reservation->event_title)) {
                $reservation->event_title = $reservation->purpose;
            }
            
            Log::info('Classroom reservation creating', [
                'classroom_id' => $reservation->classroom_id,
                'event_title' => $reservation->event_title,
                'reservation_date' => $reservation->reservation_date,
                'requester_id' => $reservation->requester_id,
                'created_by' => $reservation->created_by
            ]);
        });

        static::updating(function ($reservation) {
            // Update updated_by
            if (Auth::check()) {
                $reservation->updated_by = Auth::id();
            }
            
            // Set approved_at if being approved
            if ($reservation->isDirty('status') && $reservation->status === 'approved') {
                $reservation->approved_at = now();
                $reservation->approved_by = Auth::id();
                
                // Clear any rejection or cancellation info
                $reservation->rejected_by = null;
                $reservation->rejected_at = null;
                $reservation->rejection_reason = null;
                $reservation->cancelled_by = null;
                $reservation->cancelled_at = null;
                $reservation->cancellation_reason = null;
            }
            
            // Set rejected_at if being rejected
            if ($reservation->isDirty('status') && $reservation->status === 'rejected') {
                $reservation->rejected_at = now();
                $reservation->rejected_by = Auth::id();
            }
            
            // Set cancelled_at if being cancelled
            if ($reservation->isDirty('status') && $reservation->status === 'cancelled') {
                $reservation->cancelled_at = now();
                $reservation->cancelled_by = Auth::id();
            }
            
            // Validate reservation on update
            self::validateReservation($reservation);
            
            // Check for conflicts if dates/times are changing
            if ($reservation->isDirty('reservation_date') || 
                $reservation->isDirty('start_time') || 
                $reservation->isDirty('end_time') ||
                $reservation->isDirty('classroom_id')) {
                
                $conflicts = $reservation->checkConflicts();
                if (!empty($conflicts)) {
                    throw new \Exception('Reservation conflicts with existing bookings');
                }
            }
        });

        static::saved(function ($reservation) {
            // Clear relevant cache
            Cache::forget("classroom_reservation_{$reservation->id}");
            Cache::tags([
                "classroom_reservations_classroom_{$reservation->classroom_id}",
                "classroom_reservations_requester_{$reservation->requester_id}",
                "classroom_reservations_date_{$reservation->reservation_date}",
                "classroom_reservations_status_{$reservation->status}"
            ])->flush();
            
            // Update classroom status if needed
            $reservation->updateClassroomStatus();
            
            // Handle recurring reservations
            if ($reservation->is_recurring && $reservation->status === 'approved') {
                $reservation->createRecurringReservations();
            }
        });

        static::deleted(function ($reservation) {
            // Clear cache
            Cache::forget("classroom_reservation_{$reservation->id}");
            Cache::tags([
                "classroom_reservations_classroom_{$reservation->classroom_id}",
                "classroom_reservations_requester_{$reservation->requester_id}",
                "classroom_reservations_date_{$reservation->reservation_date}",
                "classroom_reservations_status_{$reservation->status}"
            ])->flush();
            
            // Delete related recurring reservations
            if ($reservation->is_recurring) {
                self::where('parent_reservation_id', $reservation->id)->delete();
            }
        });
    }

    /**
     * Get the classroom for this reservation.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function classroom()
    {
        return $this->belongsTo(Classroom::class, 'classroom_id');
    }

    /**
     * Get the user who requested this reservation.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function requester()
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    /**
     * Get the user who approved this reservation.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Get the user who rejected this reservation.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function rejector()
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    /**
     * Get the user who cancelled this reservation.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function canceller()
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /**
     * Get the user who created this reservation.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the user who last updated this reservation.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Get the parent reservation for recurring events.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function parentReservation()
    {
        return $this->belongsTo(self::class, 'parent_reservation_id');
    }

    /**
     * Get the child reservations for recurring events.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function childReservations()
    {
        return $this->hasMany(self::class, 'parent_reservation_id');
    }

    /**
     * Get status display name.
     *
     * @return string
     */
    public function getStatusDisplayAttribute()
    {
        $statuses = [
            'pending' => 'Pending Approval',
            'approved' => 'Approved',
            'rejected' => 'Rejected',
            'cancelled' => 'Cancelled',
            'checked_in' => 'Checked In',
            'checked_out' => 'Checked Out',
            'completed' => 'Completed',
            'no_show' => 'No Show'
        ];
        
        return $statuses[$this->status] ?? ucfirst($this->status);
    }

    /**
     * Get event type display name.
     *
     * @return string
     */
    public function getEventTypeDisplayAttribute()
    {
        $types = [
            'class' => 'Class/Teaching',
            'meeting' => 'Meeting',
            'seminar' => 'Seminar',
            'workshop' => 'Workshop',
            'training' => 'Training',
            'exam' => 'Examination',
            'presentation' => 'Presentation',
            'conference' => 'Conference',
            'cultural' => 'Cultural Event',
            'sports' => 'Sports Event',
            'club_activity' => 'Club Activity',
            'parent_meeting' => 'Parent Meeting',
            'staff_meeting' => 'Staff Meeting',
            'other' => 'Other'
        ];
        
        return $types[$this->event_type] ?? ucfirst($this->event_type);
    }

    /**
     * Get recurring type display name.
     *
     * @return string
     */
    public function getRecurringTypeDisplayAttribute()
    {
        $types = [
            'none' => 'Not Recurring',
            'daily' => 'Daily',
            'weekly' => 'Weekly',
            'biweekly' => 'Bi-weekly',
            'monthly' => 'Monthly',
            'custom' => 'Custom'
        ];
        
        return $types[$this->recurring_type] ?? ucfirst($this->recurring_type);
    }

    /**
     * Get duration in hours.
     *
     * @return float
     */
    public function getDurationHoursAttribute()
    {
        if (!$this->start_time || !$this->end_time) {
            return 0;
        }
        
        return $this->start_time->diffInHours($this->end_time, true);
    }

    /**
     * Get time slot.
     *
     * @return string
     */
    public function getTimeSlotAttribute()
    {
        if (!$this->start_time || !$this->end_time) {
            return '';
        }
        
        return $this->start_time->format('h:i A') . ' - ' . $this->end_time->format('h:i A');
    }

    /**
     * Check if reservation is recurring.
     *
     * @return bool
     */
    public function getIsRecurringAttribute()
    {
        return $this->recurring_type && $this->recurring_type !== 'none';
    }

    /**
     * Check if reservation is active.
     *
     * @return bool
     */
    public function getIsActiveAttribute()
    {
        return in_array($this->status, ['approved', 'checked_in']);
    }

    /**
     * Check if reservation is past.
     *
     * @return bool
     */
    public function getIsPastAttribute()
    {
        if (!$this->reservation_date) {
            return false;
        }
        
        $now = now();
        $reservationDateTime = \Carbon\Carbon::parse($this->reservation_date->format('Y-m-d') . ' ' . $this->end_time->format('H:i:s'));
        
        return $reservationDateTime->lt($now);
    }

    /**
     * Check if reservation is ongoing.
     *
     * @return bool
     */
    public function getIsOngoingAttribute()
    {
        if (!$this->reservation_date || !$this->start_time || !$this->end_time) {
            return false;
        }
        
        $now = now();
        $startDateTime = \Carbon\Carbon::parse($this->reservation_date->format('Y-m-d') . ' ' . $this->start_time->format('H:i:s'));
        $endDateTime = \Carbon\Carbon::parse($this->reservation_date->format('Y-m-d') . ' ' . $this->end_time->format('H:i:s'));
        
        return $now->between($startDateTime, $endDateTime);
    }

    /**
     * Check if reservation is upcoming.
     *
     * @return bool
     */
    public function getIsUpcomingAttribute()
    {
        if (!$this->reservation_date || !$this->start_time) {
            return false;
        }
        
        $now = now();
        $startDateTime = \Carbon\Carbon::parse($this->reservation_date->format('Y-m-d') . ' ' . $this->start_time->format('H:i:s'));
        
        return $startDateTime->gt($now) && !$this->is_past;
    }

    /**
     * Check for conflicts.
     *
     * @return array
     */
    public function getConflictsAttribute()
    {
        return $this->checkConflicts();
    }

    /**
     * Get requester name.
     *
     * @return string|null
     */
    public function getRequesterNameAttribute()
    {
        return $this->requester ? $this->requester->name : null;
    }

    /**
     * Get approver name.
     *
     * @return string|null
     */
    public function getApproverNameAttribute()
    {
        return $this->approver ? $this->approver->name : null;
    }

    /**
     * Get rejector name.
     *
     * @return string|null
     */
    public function getRejectorNameAttribute()
    {
        return $this->rejector ? $this->rejector->name : null;
    }

    /**
     * Get canceller name.
     *
     * @return string|null
     */
    public function getCancellerNameAttribute()
    {
        return $this->canceller ? $this->canceller->name : null;
    }

    /**
     * Get full event title.
     *
     * @return string
     */
    public function getFullEventTitleAttribute()
    {
        $title = $this->event_title;
        
        if ($this->classroom) {
            $title .= " - {$this->classroom->name}";
        }
        
        if ($this->reservation_date) {
            $title .= " ({$this->reservation_date->format('M d, Y')})";
        }
        
        return $title;
    }

    /**
     * Get setup requirements list.
     *
     * @return array
     */
    public function getSetupRequirementsListAttribute()
    {
        if (!$this->setup_requirements || !is_array($this->setup_requirements)) {
            return [];
        }
        
        return $this->setup_requirements;
    }

    /**
     * Get equipment needed list.
     *
     * @return array
     */
    public function getEquipmentNeededListAttribute()
    {
        if (!$this->equipment_needed || !is_array($this->equipment_needed)) {
            return [];
        }
        
        $equipmentList = [];
        foreach ($this->equipment_needed as $item) {
            if (is_array($item)) {
                $equipmentList[] = $item;
            } else {
                $equipmentList[] = ['name' => $item, 'quantity' => 1];
            }
        }
        
        return $equipmentList;
    }

    /**
     * Approve the reservation.
     *
     * @param  User  $approver
     * @param  string|null  $notes
     * @return bool
     */
    public function approve($approver, $notes = null)
    {
        if ($this->status === 'approved') {
            throw new \Exception('Reservation is already approved');
        }
        
        if ($this->status === 'rejected') {
            throw new \Exception('Cannot approve a rejected reservation');
        }
        
        if ($this->status === 'cancelled') {
            throw new \Exception('Cannot approve a cancelled reservation');
        }
        
        // Check for conflicts
        $conflicts = $this->checkConflicts();
        if (!empty($conflicts)) {
            throw new \Exception('Reservation conflicts with existing bookings');
        }
        
        $this->status = 'approved';
        $this->approved_by = $approver->id;
        $this->approved_at = now();
        
        if ($notes) {
            $this->notes = ($this->notes ? $this->notes . "\n" : '') . "Approval notes: {$notes}";
        }
        
        $this->save();
        
        Log::info('Classroom reservation approved', [
            'reservation_id' => $this->id,
            'classroom_id' => $this->classroom_id,
            'approver_id' => $approver->id,
            'event_title' => $this->event_title
        ]);
        
        return true;
    }

    /**
     * Reject the reservation.
     *
     * @param  User  $rejector
     * @param  string  $reason
     * @return bool
     */
    public function reject($rejector, $reason)
    {
        if ($this->status === 'rejected') {
            throw new \Exception('Reservation is already rejected');
        }
        
        if ($this->status === 'approved') {
            throw new \Exception('Cannot reject an approved reservation');
        }
        
        if ($this->status === 'cancelled') {
            throw new \Exception('Cannot reject a cancelled reservation');
        }
        
        $this->status = 'rejected';
        $this->rejected_by = $rejector->id;
        $this->rejected_at = now();
        $this->rejection_reason = $reason;
        $this->save();
        
        Log::info('Classroom reservation rejected', [
            'reservation_id' => $this->id,
            'classroom_id' => $this->classroom_id,
            'rejector_id' => $rejector->id,
            'reason' => $reason
        ]);
        
        return true;
    }

    /**
     * Cancel the reservation.
     *
     * @param  User  $canceller
     * @param  string  $reason
     * @return bool
     */
    public function cancel($canceller, $reason)
    {
        if ($this->status === 'cancelled') {
            throw new \Exception('Reservation is already cancelled');
        }
        
        if ($this->status === 'rejected') {
            throw new \Exception('Cannot cancel a rejected reservation');
        }
        
        $this->status = 'cancelled';
        $this->cancelled_by = $canceller->id;
        $this->cancelled_at = now();
        $this->cancellation_reason = $reason;
        $this->save();
        
        Log::info('Classroom reservation cancelled', [
            'reservation_id' => $this->id,
            'classroom_id' => $this->classroom_id,
            'canceller_id' => $canceller->id,
            'reason' => $reason
        ]);
        
        return true;
    }

    /**
     * Check in for the reservation.
     *
     * @param  int|null  $actualAttendees
     * @return bool
     */
    public function checkIn($actualAttendees = null)
    {
        if ($this->status !== 'approved') {
            throw new \Exception('Only approved reservations can be checked in');
        }
        
        if ($this->is_past) {
            throw new \Exception('Cannot check in to past reservation');
        }
        
        if ($this->checked_in_at) {
            throw new \Exception('Reservation is already checked in');
        }
        
        $this->status = 'checked_in';
        $this->checked_in_at = now();
        
        if ($actualAttendees) {
            $this->actual_attendees = $actualAttendees;
        }
        
        $this->save();
        
        Log::info('Classroom reservation checked in', [
            'reservation_id' => $this->id,
            'classroom_id' => $this->classroom_id,
            'checked_in_at' => $this->checked_in_at,
            'actual_attendees' => $this->actual_attendees
        ]);
        
        return true;
    }

    /**
     * Check out from the reservation.
     *
     * @param  string|null  $feedback
     * @param  int|null  $rating
     * @return bool
     */
    public function checkOut($feedback = null, $rating = null)
    {
        if ($this->status !== 'checked_in') {
            throw new \Exception('Only checked-in reservations can be checked out');
        }
        
        $this->status = 'completed';
        $this->checked_out_at = now();
        
        if ($feedback) {
            $this->feedback = $feedback;
        }
        
        if ($rating !== null && $rating >= 1 && $rating <= 5) {
            $this->rating = $rating;
        }
        
        $this->save();
        
        Log::info('Classroom reservation checked out', [
            'reservation_id' => $this->id,
            'classroom_id' => $this->classroom_id,
            'checked_out_at' => $this->checked_out_at,
            'rating' => $this->rating
        ]);
        
        return true;
    }

    /**
     * Mark as no show.
     *
     * @return bool
     */
    public function markNoShow()
    {
        if ($this->status !== 'approved') {
            throw new \Exception('Only approved reservations can be marked as no show');
        }
        
        if (!$this->is_past) {
            throw new \Exception('Cannot mark future reservation as no show');
        }
        
        $this->status = 'no_show';
        $this->save();
        
        Log::info('Classroom reservation marked as no show', [
            'reservation_id' => $this->id,
            'classroom_id' => $this->classroom_id,
            'marked_by' => Auth::id()
        ]);
        
        return true;
    }

    /**
     * Check for scheduling conflicts.
     *
     * @return array
     */
    public function checkConflicts()
    {
        if (!$this->classroom_id || !$this->reservation_date || !$this->start_time || !$this->end_time) {
            return [];
        }
        
        $conflicts = [];
        
        // Check for timetable conflicts
        $timetableConflicts = TimetableEntry::where('classroom_id', $this->classroom_id)
            ->where('day', $this->reservation_date->format('l'))
            ->where(function($query) {
                $query->whereBetween('start_time', [$this->start_time, $this->end_time])
                      ->orWhereBetween('end_time', [$this->start_time, $this->end_time])
                      ->orWhere(function($q) {
                          $q->where('start_time', '<', $this->start_time)
                            ->where('end_time', '>', $this->end_time);
                      });
            })
            ->where('status', '!=', 'cancelled')
            ->get();
        
        if ($timetableConflicts->isNotEmpty()) {
            $conflicts['timetable'] = $timetableConflicts;
        }
        
        // Check for other reservation conflicts
        $reservationConflicts = self::where('classroom_id', $this->classroom_id)
            ->where('reservation_date', $this->reservation_date)
            ->where('id', '!=', $this->id)
            ->whereIn('status', ['approved', 'checked_in'])
            ->where(function($query) {
                $query->whereBetween('start_time', [$this->start_time, $this->end_time])
                      ->orWhereBetween('end_time', [$this->start_time, $this->end_time])
                      ->orWhere(function($q) {
                          $q->where('start_time', '<', $this->start_time)
                            ->where('end_time', '>', $this->end_time);
                      });
            })
            ->get();
        
        if ($reservationConflicts->isNotEmpty()) {
            $conflicts['reservations'] = $reservationConflicts;
        }
        
        // Check for maintenance conflicts
        $maintenanceConflicts = ClassroomMaintenance::where('classroom_id', $this->classroom_id)
            ->whereIn('status', ['in_progress', 'assigned', 'reported'])
            ->whereDate('start_date', '<=', $this->reservation_date)
            ->whereDate('expected_completion', '>=', $this->reservation_date)
            ->get();
        
        if ($maintenanceConflicts->isNotEmpty()) {
            $conflicts['maintenance'] = $maintenanceConflicts;
        }
        
        return $conflicts;
    }

    /**
     * Create recurring reservations.
     *
     * @return array
     */
    public function createRecurringReservations()
    {
        if (!$this->is_recurring || !$this->recurring_end_date) {
            return [];
        }
        
        $createdReservations = [];
        $currentDate = $this->reservation_date->copy()->addDay();
        $endDate = \Carbon\Carbon::parse($this->recurring_end_date);
        
        while ($currentDate <= $endDate) {
            // Check if this day should have a reservation
            if ($this->shouldCreateRecurringForDate($currentDate)) {
                $recurringReservation = $this->replicate();
                $recurringReservation->parent_reservation_id = $this->id;
                $recurringReservation->reservation_date = $currentDate;
                $recurringReservation->status = 'approved';
                $recurringReservation->save();
                
                $createdReservations[] = $recurringReservation;
            }
            
            // Move to next date based on recurring type
            $currentDate = $this->getNextRecurringDate($currentDate);
        }
        
        return $createdReservations;
    }

    /**
     * Check if recurring reservation should be created for a date.
     *
     * @param  \Carbon\Carbon  $date
     * @return bool
     */
    private function shouldCreateRecurringForDate($date)
    {
        switch ($this->recurring_type) {
            case 'daily':
                return true;
                
            case 'weekly':
                return in_array($date->format('l'), $this->recurring_days ?? []);
                
            case 'biweekly':
                $weekNumber = floor($date->diffInWeeks($this->reservation_date));
                return $weekNumber % 2 === 0 && in_array($date->format('l'), $this->recurring_days ?? []);
                
            case 'monthly':
                return $date->day === $this->reservation_date->day;
                
            case 'custom':
                // Custom logic based on recurring_days
                return in_array($date->format('Y-m-d'), $this->recurring_days ?? []);
                
            default:
                return false;
        }
    }

    /**
     * Get next recurring date.
     *
     * @param  \Carbon\Carbon  $currentDate
     * @return \Carbon\Carbon
     */
    private function getNextRecurringDate($currentDate)
    {
        switch ($this->recurring_type) {
            case 'daily':
                return $currentDate->addDay();
                
            case 'weekly':
            case 'biweekly':
                return $currentDate->addWeek();
                
            case 'monthly':
                return $currentDate->addMonth();
                
            case 'custom':
                // For custom, we need to find the next date in recurring_days
                $nextDate = null;
                $allDates = $this->recurring_days ?? [];
                sort($allDates);
                
                foreach ($allDates as $dateStr) {
                    $date = \Carbon\Carbon::parse($dateStr);
                    if ($date > $currentDate) {
                        $nextDate = $date;
                        break;
                    }
                }
                
                return $nextDate ?: $currentDate->addDay();
                
            default:
                return $currentDate->addDay();
        }
    }

    /**
     * Update classroom status based on reservation.
     *
     * @return void
     */
    public function updateClassroomStatus()
    {
        if (!$this->classroom) {
            return;
        }
        
        $classroom = $this->classroom;
        
        // If reservation is approved and happening now, set classroom to reserved
        if ($this->status === 'approved' && $this->is_ongoing) {
            if ($classroom->status !== 'reserved') {
                $classroom->status = 'reserved';
                $classroom->save();
            }
        } 
        // If reservation is completed, cancelled, or rejected, restore classroom status
        elseif (in_array($this->status, ['completed', 'cancelled', 'rejected', 'no_show'])) {
            // Check if there are other active reservations
            $activeReservation = self::where('classroom_id', $classroom->id)
                ->where('id', '!=', $this->id)
                ->where('status', 'approved')
                ->where(function($query) {
                    $now = now();
                    $query->where(function($q) use ($now) {
                        $q->whereDate('reservation_date', $now->format('Y-m-d'))
                          ->whereTime('start_time', '<=', $now->format('H:i:s'))
                          ->whereTime('end_time', '>=', $now->format('H:i:s'));
                    });
                })
                ->exists();
                
            if (!$activeReservation && $classroom->status === 'reserved') {
                $classroom->status = 'available';
                $classroom->save();
            }
        }
    }

    /**
     * Get reservation details.
     *
     * @return array
     */
    public function getDetails()
    {
        return [
            'id' => $this->id,
            'classroom' => $this->classroom ? [
                'id' => $this->classroom->id,
                'name' => $this->classroom->name,
                'code' => $this->classroom->code,
                'building' => $this->classroom->building,
                'room_number' => $this->classroom->room_number,
                'capacity' => $this->classroom->capacity
            ] : null,
            'event_type' => $this->event_type,
            'event_type_display' => $this->event_type_display,
            'event_title' => $this->event_title,
            'event_description' => $this->event_description,
            'purpose' => $this->purpose,
            'reservation_date' => $this->reservation_date,
            'start_time' => $this->start_time,
            'end_time' => $this->end_time,
            'time_slot' => $this->time_slot,
            'duration_hours' => $this->duration_hours,
            'recurring_type' => $this->recurring_type,
            'recurring_type_display' => $this->recurring_type_display,
            'is_recurring' => $this->is_recurring,
            'recurring_days' => $this->recurring_days,
            'recurring_end_date' => $this->recurring_end_date,
            'expected_attendees' => $this->expected_attendees,
            'actual_attendees' => $this->actual_attendees,
            'setup_requirements' => $this->setup_requirements_list,
            'equipment_needed' => $this->equipment_needed_list,
            'special_requirements' => $this->special_requirements,
            'status' => $this->status,
            'status_display' => $this->status_display,
            'requester' => $this->requester ? [
                'id' => $this->requester->id,
                'name' => $this->requester->name,
                'email' => $this->requester->email
            ] : null,
            'approver' => $this->approver ? [
                'id' => $this->approver->id,
                'name' => $this->approver->name,
                'email' => $this->approver->email
            ] : null,
            'rejector' => $this->rejector ? [
                'id' => $this->rejector->id,
                'name' => $this->rejector->name,
                'email' => $this->rejector->email
            ] : null,
            'canceller' => $this->canceller ? [
                'id' => $this->canceller->id,
                'name' => $this->canceller->name,
                'email' => $this->canceller->email
            ] : null,
            'timeline' => [
                'created_at' => $this->created_at,
                'approved_at' => $this->approved_at,
                'rejected_at' => $this->rejected_at,
                'cancelled_at' => $this->cancelled_at,
                'checked_in_at' => $this->checked_in_at,
                'checked_out_at' => $this->checked_out_at
            ],
            'rejection_reason' => $this->rejection_reason,
            'cancellation_reason' => $this->cancellation_reason,
            'feedback' => $this->feedback,
            'rating' => $this->rating,
            'is_active' => $this->is_active,
            'is_past' => $this->is_past,
            'is_ongoing' => $this->is_ongoing,
            'is_upcoming' => $this->is_upcoming,
            'conflicts' => $this->conflicts,
            'notes' => $this->notes,
            'child_reservations' => $this->childReservations()->count()
        ];
    }

    /**
     * Validate reservation.
     *
     * @param  ClassroomReservation  $reservation
     * @return void
     * @throws \Exception
     */
    private static function validateReservation($reservation)
    {
        // Validate dates and times
        if ($reservation->start_time >= $reservation->end_time) {
            throw new \Exception('Start time must be before end time');
        }
        
        // Validate reservation date is not in the past
        if ($reservation->reservation_date && $reservation->reservation_date < now()->startOfDay()) {
            throw new \Exception('Cannot reserve classroom for past dates');
        }
        
        // Validate expected attendees
        if ($reservation->expected_attendees && $reservation->classroom) {
            if ($reservation->expected_attendees > $reservation->classroom->capacity) {
                throw new \Exception("Expected attendees exceed classroom capacity of {$reservation->classroom->capacity}");
            }
        }
        
        // Validate recurring dates
        if ($reservation->recurring_end_date && $reservation->reservation_date) {
            if ($reservation->recurring_end_date <= $reservation->reservation_date) {
                throw new \Exception('Recurring end date must be after reservation date');
            }
        }
        
        // Validate rating
        if ($reservation->rating && ($reservation->rating < 1 || $reservation->rating > 5)) {
            throw new \Exception('Rating must be between 1 and 5');
        }
        
        // Validate event type
        $validEventTypes = ['class', 'meeting', 'seminar', 'workshop', 'training', 'exam', 
                           'presentation', 'conference', 'cultural', 'sports', 'club_activity', 
                           'parent_meeting', 'staff_meeting', 'other'];
        if (!in_array($reservation->event_type, $validEventTypes)) {
            throw new \Exception('Invalid event type');
        }
        
        // Validate recurring type
        $validRecurringTypes = ['none', 'daily', 'weekly', 'biweekly', 'monthly', 'custom'];
        if (!in_array($reservation->recurring_type, $validRecurringTypes)) {
            throw new \Exception('Invalid recurring type');
        }
    }

    /**
     * Get reservations for a classroom.
     *
     * @param  int  $classroomId
     * @param  array  $filters
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getForClassroom($classroomId, $filters = [])
    {
        $cacheKey = "classroom_reservations_classroom_{$classroomId}_" . md5(json_encode($filters));
        
        return Cache::remember($cacheKey, now()->addHours(6), function () use ($classroomId, $filters) {
            $query = self::where('classroom_id', $classroomId)
                ->with(['classroom', 'requester', 'approver'])
                ->orderBy('reservation_date', 'desc')
                ->orderBy('start_time', 'desc');
            
            // Apply filters
            if (isset($filters['date_from'])) {
                $query->whereDate('reservation_date', '>=', $filters['date_from']);
            }
            
            if (isset($filters['date_to'])) {
                $query->whereDate('reservation_date', '<=', $filters['date_to']);
            }
            
            if (isset($filters['status'])) {
                $query->where('status', $filters['status']);
            }
            
            if (isset($filters['event_type'])) {
                $query->where('event_type', $filters['event_type']);
            }
            
            if (isset($filters['requester_id'])) {
                $query->where('requester_id', $filters['requester_id']);
            }
            
            if (isset($filters['is_active'])) {
                if ($filters['is_active']) {
                    $query->where('status', 'approved')
                          ->whereDate('reservation_date', '>=', now()->format('Y-m-d'));
                }
            }
            
            if (isset($filters['is_pending'])) {
                if ($filters['is_pending']) {
                    $query->where('status', 'pending');
                }
            }
            
            return $query->get();
        });
    }

    /**
     * Get reservations for a user.
     *
     * @param  int  $userId
     * @param  array  $filters
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getForUser($userId, $filters = [])
    {
        $cacheKey = "classroom_reservations_user_{$userId}_" . md5(json_encode($filters));
        
        return Cache::remember($cacheKey, now()->addHours(6), function () use ($userId, $filters) {
            $query = self::where('requester_id', $userId)
                ->with(['classroom', 'approver', 'rejector'])
                ->orderBy('reservation_date', 'desc')
                ->orderBy('start_time', 'desc');
            
            // Apply filters
            if (isset($filters['date_from'])) {
                $query->whereDate('reservation_date', '>=', $filters['date_from']);
            }
            
            if (isset($filters['date_to'])) {
                $query->whereDate('reservation_date', '<=', $filters['date_to']);
            }
            
            if (isset($filters['status'])) {
                $query->where('status', $filters['status']);
            }
            
            if (isset($filters['classroom_id'])) {
                $query->where('classroom_id', $filters['classroom_id']);
            }
            
            if (isset($filters['is_upcoming'])) {
                if ($filters['is_upcoming']) {
                    $query->where('status', 'approved')
                          ->whereDate('reservation_date', '>=', now()->format('Y-m-d'));
                }
            }
            
            return $query->get();
        });
    }

    /**
     * Get upcoming reservations.
     *
     * @param  array  $filters
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getUpcoming($filters = [])
    {
        $cacheKey = 'classroom_reservations_upcoming_' . md5(json_encode($filters));
        
        return Cache::remember($cacheKey, now()->addHour(), function () use ($filters) {
            $query = self::where('status', 'approved')
                ->whereDate('reservation_date', '>=', now()->format('Y-m-d'))
                ->with(['classroom', 'requester'])
                ->orderBy('reservation_date', 'asc')
                ->orderBy('start_time', 'asc');
            
            // Apply filters
            if (isset($filters['classroom_id'])) {
                $query->where('classroom_id', $filters['classroom_id']);
            }
            
            if (isset($filters['date_from'])) {
                $query->whereDate('reservation_date', '>=', $filters['date_from']);
            }
            
            if (isset($filters['date_to'])) {
                $query->whereDate('reservation_date', '<=', $filters['date_to']);
            }
            
            if (isset($filters['event_type'])) {
                $query->where('event_type', $filters['event_type']);
            }
            
            return $query->get();
        });
    }

    /**
     * Get reservations for a specific date.
     *
     * @param  string  $date
     * @param  array  $filters
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getForDate($date, $filters = [])
    {
        $cacheKey = "classroom_reservations_date_{$date}_" . md5(json_encode($filters));
        
        return Cache::remember($cacheKey, now()->addHours(6), function () use ($date, $filters) {
            $query = self::whereDate('reservation_date', $date)
                ->whereIn('status', ['approved', 'checked_in'])
                ->with(['classroom', 'requester'])
                ->orderBy('start_time', 'asc');
            
            // Apply filters
            if (isset($filters['classroom_id'])) {
                $query->where('classroom_id', $filters['classroom_id']);
            }
            
            if (isset($filters['building'])) {
                $query->whereHas('classroom', function($q) use ($filters) {
                    $q->where('building', $filters['building']);
                });
            }
            
            if (isset($filters['event_type'])) {
                $query->where('event_type', $filters['event_type']);
            }
            
            return $query->get();
        });
    }

    /**
     * Check classroom availability for a time slot.
     *
     * @param  int  $classroomId
     * @param  string  $date
     * @param  string  $startTime
     * @param  string  $endTime
     * @param  int|null  $excludeReservationId
     * @return array
     */
    public static function checkAvailability($classroomId, $date, $startTime, $endTime, $excludeReservationId = null)
    {
        $classroom = Classroom::find($classroomId);
        
        if (!$classroom) {
            return ['available' => false, 'reason' => 'Classroom not found'];
        }
        
        if (!$classroom->is_reservable) {
            return ['available' => false, 'reason' => 'Classroom is not reservable'];
        }
        
        if ($classroom->status !== 'available') {
            return ['available' => false, 'reason' => "Classroom is currently {$classroom->status_display}"];
        }
        
        // Check for reservation conflicts
        $conflict = self::where('classroom_id', $classroomId)
            ->whereDate('reservation_date', $date)
            ->where('id', '!=', $excludeReservationId)
            ->whereIn('status', ['approved', 'checked_in'])
            ->where(function($query) use ($startTime, $endTime) {
                $query->whereBetween('start_time', [$startTime, $endTime])
                      ->orWhereBetween('end_time', [$startTime, $endTime])
                      ->orWhere(function($q) use ($startTime, $endTime) {
                          $q->where('start_time', '<', $startTime)
                            ->where('end_time', '>', $endTime);
                      });
            })
            ->first();
        
        if ($conflict) {
            return [
                'available' => false,
                'reason' => 'Classroom already reserved for this time slot',
                'conflict_with' => $conflict
            ];
        }
        
        // Check for timetable conflicts
        $dayOfWeek = \Carbon\Carbon::parse($date)->format('l');
        $timetableConflict = TimetableEntry::where('classroom_id', $classroomId)
            ->where('day', $dayOfWeek)
            ->where('status', '!=', 'cancelled')
            ->where(function($query) use ($startTime, $endTime) {
                $query->whereBetween('start_time', [$startTime, $endTime])
                      ->orWhereBetween('end_time', [$startTime, $endTime])
                      ->orWhere(function($q) use ($startTime, $endTime) {
                          $q->where('start_time', '<', $startTime)
                            ->where('end_time', '>', $endTime);
                      });
            })
            ->first();
        
        if ($timetableConflict) {
            return [
                'available' => false,
                'reason' => 'Classroom scheduled for regular class during this time',
                'conflict_with' => $timetableConflict
            ];
        }
        
        return [
            'available' => true,
            'classroom' => [
                'id' => $classroom->id,
                'name' => $classroom->name,
                'capacity' => $classroom->capacity,
                'facilities' => $classroom->facilities_list
            ]
        ];
    }

    /**
     * Get reservation statistics.
     *
     * @param  array  $filters
     * @return array
     */
    public static function getStatistics($filters = [])
    {
        $query = self::query();
        
        // Apply filters
        if (isset($filters['date_from'])) {
            $query->whereDate('reservation_date', '>=', $filters['date_from']);
        }
        
        if (isset($filters['date_to'])) {
            $query->whereDate('reservation_date', '<=', $filters['date_to']);
        }
        
        if (isset($filters['classroom_id'])) {
            $query->where('classroom_id', $filters['classroom_id']);
        }
        
        if (isset($filters['building'])) {
            $query->whereHas('classroom', function($q) use ($filters) {
                $q->where('building', $filters['building']);
            });
        }
        
        $total = $query->count();
        $approved = $query->where('status', 'approved')->count();
        $pending = $query->where('status', 'pending')->count();
        $rejected = $query->where('status', 'rejected')->count();
        $cancelled = $query->where('status', 'cancelled')->count();
        $completed = $query->where('status', 'completed')->count();
        $noShow = $query->where('status', 'no_show')->count();
        
        // Get by event type
        $byEventType = $query->clone()
            ->selectRaw('event_type, COUNT(*) as count')
            ->groupBy('event_type')
            ->pluck('count', 'event_type')
            ->toArray();
        
        // Get by day of week
        $byDayOfWeek = $query->clone()
            ->selectRaw('DAYNAME(reservation_date) as day, COUNT(*) as count')
            ->groupBy('day')
            ->orderByRaw('FIELD(day, "Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday", "Sunday")')
            ->pluck('count', 'day')
            ->toArray();
        
        // Get average attendees
        $avgAttendees = $query->clone()
            ->whereNotNull('actual_attendees')
            ->avg('actual_attendees');
        
        // Get total hours reserved
        $totalHours = $query->clone()
            ->where('status', 'completed')
            ->get()
            ->sum('duration_hours');
        
        // Get average rating
        $avgRating = $query->clone()
            ->whereNotNull('rating')
            ->avg('rating');
        
        return [
            'total' => $total,
            'approved' => $approved,
            'pending' => $pending,
            'rejected' => $rejected,
            'cancelled' => $cancelled,
            'completed' => $completed,
            'no_show' => $noShow,
            'approval_rate' => $total > 0 ? round(($approved / $total) * 100, 2) : 0,
            'completion_rate' => $approved > 0 ? round(($completed / $approved) * 100, 2) : 0,
            'no_show_rate' => $approved > 0 ? round(($noShow / $approved) * 100, 2) : 0,
            'by_event_type' => $byEventType,
            'by_day_of_week' => $byDayOfWeek,
            'average_attendees' => round($avgAttendees ?? 0, 2),
            'total_hours_reserved' => round($totalHours, 2),
            'average_rating' => round($avgRating ?? 0, 2)
        ];
    }

    /**
     * Export reservation data to array.
     *
     * @return array
     */
    public function exportToArray()
    {
        return [
            'id' => $this->id,
            'classroom' => $this->classroom ? $this->classroom->full_name : null,
            'event_type' => $this->event_type_display,
            'event_title' => $this->event_title,
            'purpose' => $this->purpose,
            'requester' => $this->requester_name,
            'reservation_date' => $this->reservation_date,
            'time_slot' => $this->time_slot,
            'duration_hours' => $this->duration_hours,
            'recurring_type' => $this->recurring_type_display,
            'expected_attendees' => $this->expected_attendees,
            'actual_attendees' => $this->actual_attendees,
            'setup_requirements' => $this->setup_requirements_list,
            'equipment_needed' => $this->equipment_needed_list,
            'special_requirements' => $this->special_requirements,
            'status' => $this->status_display,
            'approver' => $this->approver_name,
            'approved_at' => $this->approved_at,
            'rejector' => $this->rejector_name,
            'rejected_at' => $this->rejected_at,
            'rejection_reason' => $this->rejection_reason,
            'canceller' => $this->canceller_name,
            'cancelled_at' => $this->cancelled_at,
            'cancellation_reason' => $this->cancellation_reason,
            'checked_in_at' => $this->checked_in_at,
            'checked_out_at' => $this->checked_out_at,
            'feedback' => $this->feedback,
            'rating' => $this->rating,
            'notes' => $this->notes,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at
        ];
    }

    /**
     * Scope a query to only include active reservations.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'approved')
                     ->whereDate('reservation_date', '>=', now()->format('Y-m-d'));
    }

    /**
     * Scope a query to only include pending reservations.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope a query to only include upcoming reservations.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeUpcoming($query)
    {
        return $query->where('status', 'approved')
                     ->whereDate('reservation_date', '>=', now()->format('Y-m-d'))
                     ->orderBy('reservation_date', 'asc')
                     ->orderBy('start_time', 'asc');
    }

    /**
     * Scope a query to only include reservations for a specific date.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  string  $date
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForDate($query, $date)
    {
        return $query->whereDate('reservation_date', $date);
    }

    /**
     * Scope a query to only include reservations with specific event type.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  string  $eventType
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWithEventType($query, $eventType)
    {
        return $query->where('event_type', $eventType);
    }

    /**
     * Scope a query to only include recurring reservations.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeRecurring($query)
    {
        return $query->where('recurring_type', '!=', 'none')
                     ->whereNotNull('recurring_end_date');
    }

    /**
     * Scope a query to only include completed reservations.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    /**
     * Scope a query to only include reservations with feedback.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWithFeedback($query)
    {
        return $query->whereNotNull('feedback')
                     ->where('feedback', '!=', '');
    }
}