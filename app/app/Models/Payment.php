<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'school_id',
        'student_id',
        'invoice_number',
        'payment_type',
        'description',
        'amount',
        'amount_paid',
        'balance',
        'due_date',
        'status',
        'payment_method',
        'transaction_id',
        'payment_date',
        'receipt_number',
        'received_by',
        'notes',
        'payment_details',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'amount_paid' => 'decimal:2',
        'balance' => 'decimal:2',
        'due_date' => 'date',
        'payment_date' => 'date',
        'payment_details' => 'array',
    ];

    // Relationships
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    // Accessors
    public function getFormattedAmountAttribute(): string
    {
        return '₦' . number_format((float) $this->amount, 2);
    }

    public function getFormattedAmountPaidAttribute(): string
    {
        return '₦' . number_format((float) $this->amount_paid, 2);
    }

    public function getFormattedBalanceAttribute(): string
    {
        return '₦' . number_format((float) $this->balance, 2);
    }

    public function getIsOverdueAttribute(): bool
    {
        return $this->status === 'pending' && $this->due_date < now();
    }

    public function getPaymentPercentageAttribute(): float
    {
        if ($this->amount <= 0) return 0;
        return ($this->amount_paid / $this->amount) * 100;
    }

    public function getIsPaidAttribute(): bool
    {
        return $this->status === 'paid';
    }

    public function getIsPartialAttribute(): bool
    {
        return $this->status === 'partial';
    }

    // Scopes
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopePaid($query)
    {
        return $query->where('status', 'paid');
    }

    public function scopeOverdue($query)
    {
        return $query->where('status', 'pending')
            ->where('due_date', '<', now());
    }

    public function scopeForStudent($query, $studentId)
    {
        return $query->where('student_id', $studentId);
    }

    public function scopeForSchool($query, $schoolId)
    {
        return $query->where('school_id', $schoolId);
    }

    public function scopeForPaymentType($query, $type)
    {
        return $query->where('payment_type', $type);
    }

    // Methods
    public function recordPayment($amount, $method, $transactionId = null): void
    {
        $newAmountPaid = $this->amount_paid + $amount;
        $newBalance = max(0, $this->amount - $newAmountPaid);
        
        $status = $newBalance <= 0 ? 'paid' : 'partial';
        
        $this->update([
            'amount_paid' => $newAmountPaid,
            'balance' => $newBalance,
            'status' => $status,
            'payment_method' => $method,
            'transaction_id' => $transactionId,
            'payment_date' => now(),
        ]);
    }
}