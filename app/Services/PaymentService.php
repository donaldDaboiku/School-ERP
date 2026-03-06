<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\Student;
use App\Models\School;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PaymentService
{
    /**
     * Create a new payment invoice
     */
    public function createInvoice(array $data): Payment
    {
        return DB::transaction(function () use ($data) {
            $invoiceNumber = $this->generateInvoiceNumber($data['school_id']);
            
            $payment = Payment::create([
                'school_id' => $data['school_id'],
                'student_id' => $data['student_id'],
                'invoice_number' => $invoiceNumber,
                'payment_type' => $data['payment_type'],
                'description' => $data['description'],
                'amount' => $data['amount'],
                'amount_paid' => 0,
                'balance' => $data['amount'],
                'due_date' => $data['due_date'] ?? now()->addDays(30),
                'status' => 'pending',
                'notes' => $data['notes'] ?? null,
            ]);
            
            // Send notification if requested
            if ($data['send_notification'] ?? false) {
                $this->sendInvoiceNotification($payment);
            }
            
            return $payment;
        });
    }

    /**
     * Record payment against invoice
     */
    public function recordPayment(Payment $payment, array $data): Payment
    {
        return DB::transaction(function () use ($payment, $data) {
            $newAmountPaid = $payment->amount_paid + $data['amount'];
            $newBalance = max(0, $payment->amount - $newAmountPaid);
            
            $status = $newBalance <= 0 ? 'paid' : 'partial';
            
            $payment->update([
                'amount_paid' => $newAmountPaid,
                'balance' => $newBalance,
                'status' => $status,
                'payment_method' => $data['payment_method'],
                'transaction_id' => $data['transaction_id'] ?? null,
                'payment_date' => now(),
                'received_by' => auth()->id(),
                'receipt_number' => $this->generateReceiptNumber($payment->school_id),
            ]);
            
            // Update payment details if provided
            if (!empty($data['payment_details'])) {
                $existingDetails = $payment->payment_details ?? [];
                $newDetails = array_merge($existingDetails, [
                    'payment_recorded' => [
                        'amount' => $data['amount'],
                        'method' => $data['payment_method'],
                        'date' => now()->toISOString(),
                        'recorded_by' => auth()->id(),
                    ]
                ]);
                
                $payment->update(['payment_details' => $newDetails]);
            }
            
            // Send payment confirmation
            if ($data['send_confirmation'] ?? true) {
                $this->sendPaymentConfirmation($payment, $data['amount']);
            }
            
            return $payment->fresh();
        });
    }

    /**
     * Generate bulk invoices for class/term
     */
    public function generateBulkInvoices(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $results = [
                'total' => 0,
                'successful' => 0,
                'failed' => 0,
                'invoices' => [],
                'errors' => [],
            ];
            
            $school = School::findOrFail($data['school_id']);
            $term = $data['term_id'] ? \App\Models\TermSemester::find($data['term_id']) : null;
            
            // Get students based on criteria
            $students = $this->getStudentsForBilling($data);
            
            foreach ($students as $student) {
                try {
                    $invoiceData = [
                        'school_id' => $school->id,
                        'student_id' => $student->id,
                        'payment_type' => $data['payment_type'],
                        'description' => $this->generateInvoiceDescription($data, $student, $term),
                        'amount' => $this->calculateInvoiceAmount($data, $student),
                        'due_date' => $data['due_date'] ?? now()->addDays(30),
                        'notes' => $data['notes'] ?? null,
                        'send_notification' => $data['send_notification'] ?? false,
                    ];
                    
                    $invoice = $this->createInvoice($invoiceData);
                    
                    $results['successful']++;
                    $results['invoices'][] = [
                        'student' => $student->full_name,
                        'admission_number' => $student->admission_number,
                        'invoice_number' => $invoice->invoice_number,
                        'amount' => $invoice->amount,
                        'due_date' => $invoice->due_date->format('d/m/Y'),
                    ];
                    
                } catch (\Exception $e) {
                    $results['failed']++;
                    $results['errors'][] = [
                        'student' => $student->full_name,
                        'error' => $e->getMessage(),
                    ];
                }
                
                $results['total']++;
            }
            
            return $results;
        });
    }

    /**
     * Process refund for payment
     */
    public function processRefund(Payment $payment, array $data): Payment
    {
        return DB::transaction(function () use ($payment, $data) {
            if ($payment->amount_paid < $data['refund_amount']) {
                throw new \Exception('Refund amount cannot exceed paid amount');
            }
            
            $newAmountPaid = $payment->amount_paid - $data['refund_amount'];
            $newBalance = $payment->amount - $newAmountPaid;
            
            $payment->update([
                'amount_paid' => $newAmountPaid,
                'balance' => $newBalance,
                'status' => $newAmountPaid > 0 ? 'partial' : 'pending',
            ]);
            
            // Record refund details
            $refundDetails = $payment->payment_details ?? [];
            $refundDetails['refunds'][] = [
                'amount' => $data['refund_amount'],
                'reason' => $data['reason'],
                'processed_by' => auth()->id(),
                'processed_at' => now()->toISOString(),
                'method' => $data['refund_method'] ?? null,
                'reference' => $data['refund_reference'] ?? null,
            ];
            
            $payment->update(['payment_details' => $refundDetails]);
            
            // Send refund notification
            $this->sendRefundNotification($payment, $data['refund_amount'], $data['reason']);
            
            return $payment->fresh();
        });
    }

    /**
     * Get payment statistics
     */
    public function getPaymentStatistics(School $school, array $filters = []): array
    {
        $query = $school->payments();
        
        if (isset($filters['start_date'])) {
            $query->where('created_at', '>=', $filters['start_date']);
        }
        
        if (isset($filters['end_date'])) {
            $query->where('created_at', '<=', $filters['end_date']);
        }
        
        if (isset($filters['payment_type'])) {
            $query->where('payment_type', $filters['payment_type']);
        }
        
        $payments = $query->get();
        
        return [
            'total_invoices' => $payments->count(),
            'total_amount' => $payments->sum('amount'),
            'total_paid' => $payments->sum('amount_paid'),
            'total_balance' => $payments->sum('balance'),
            'by_status' => $payments->groupBy('status')->map->count(),
            'by_type' => $payments->groupBy('payment_type')->map(function ($group) {
                return [
                    'count' => $group->count(),
                    'amount' => $group->sum('amount'),
                    'paid' => $group->sum('amount_paid'),
                ];
            }),
            'monthly_collection' => $this->getMonthlyCollection($school, $filters),
            'overdue_invoices' => $this->getOverdueInvoices($school, $filters),
        ];
    }

    /**
     * Generate payment receipt
     */
    public function generateReceipt(Payment $payment): array
    {
        $student = $payment->student;
        $school = $payment->school;
        
        return [
            'receipt_number' => $payment->receipt_number ?? 'N/A',
            'invoice_number' => $payment->invoice_number,
            'date' => now()->format('d/m/Y'),
            'time' => now()->format('h:i A'),
            'school' => [
                'name' => $school->name,
                'address' => $school->address,
                'phone' => $school->phone,
                'email' => $school->email,
            ],
            'student' => [
                'name' => $student->full_name,
                'admission_number' => $student->admission_number,
                'class' => $student->class->name ?? 'N/A',
            ],
            'payment_details' => [
                'description' => $payment->description,
                'amount' => $payment->amount,
                'amount_paid' => $payment->amount_paid,
                'balance' => $payment->balance,
                'payment_method' => $payment->payment_method ?? 'N/A',
                'transaction_id' => $payment->transaction_id ?? 'N/A',
                'payment_date' => $payment->payment_date?->format('d/m/Y') ?? 'N/A',
            ],
            'breakdown' => $this->getPaymentBreakdown($payment),
            'received_by' => $payment->receiver->name ?? 'System',
            'notes' => $payment->notes,
        ];
    }

    /**
     * Send payment reminders
     */
    public function sendPaymentReminders(array $filters = []): array
    {
        $query = Payment::where('status', 'pending')
            ->where('due_date', '<=', now()->addDays(7));
        
        if (isset($filters['school_id'])) {
            $query->where('school_id', $filters['school_id']);
        }
        
        $payments = $query->with(['student.user', 'school'])->get();
        
        $results = [
            'total' => $payments->count(),
            'sent' => 0,
            'failed' => 0,
            'details' => [],
        ];
        
        foreach ($payments as $payment) {
            try {
                $this->sendReminderNotification($payment);
                
                // Update last reminder sent date
                $details = $payment->payment_details ?? [];
                $details['last_reminder_sent'] = now()->toISOString();
                $payment->update(['payment_details' => $details]);
                
                $results['sent']++;
                $results['details'][] = [
                    'invoice' => $payment->invoice_number,
                    'student' => $payment->student->full_name,
                    'amount' => $payment->balance,
                    'due_date' => $payment->due_date->format('d/m/Y'),
                    'status' => 'sent',
                ];
                
            } catch (\Exception $e) {
                $results['failed']++;
                $results['details'][] = [
                    'invoice' => $payment->invoice_number,
                    'student' => $payment->student->full_name,
                    'error' => $e->getMessage(),
                    'status' => 'failed',
                ];
            }
        }
        
        return $results;
    }

    /**
     * Generate invoice number
     */
    private function generateInvoiceNumber(int $schoolId): string
    {
        $school = School::find($schoolId);
        $year = now()->format('y');
        $month = now()->format('m');
        
        $count = Payment::where('school_id', $schoolId)
            ->whereYear('created_at', now()->year)
            ->whereMonth('created_at', now()->month)
            ->count();
        
        return 'INV' . $school->code . $year . $month . str_pad($count + 1, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Generate receipt number
     */
    private function generateReceiptNumber(int $schoolId): string
    {
        $school = School::find($schoolId);
        $year = now()->format('y');
        
        $count = Payment::where('school_id', $schoolId)
            ->whereYear('payment_date', now()->year)
            ->whereNotNull('receipt_number')
            ->count();
        
        return 'RCT' . $school->code . $year . str_pad($count + 1, 5, '0', STR_PAD_LEFT);
    }

    /**
     * Get students for bulk billing
     */
    private function getStudentsForBilling(array $data): \Illuminate\Database\Eloquent\Collection
    {
        $query = Student::where('school_id', $data['school_id']);
        
        if (isset($data['class_id'])) {
            $query->where('class_id', $data['class_id']);
        }
        
        if (isset($data['student_ids']) && is_array($data['student_ids'])) {
            $query->whereIn('id', $data['student_ids']);
        }
        
        if (isset($data['student_category'])) {
            $query->where('student_category', $data['student_category']);
        }
        
        return $query->get();
    }

    /**
     * Generate invoice description
     */
    private function generateInvoiceDescription(array $data, Student $student, $term): string
    {
        $descriptions = [
            'tuition' => 'Tuition Fee',
            'exam' => 'Examination Fee',
            'uniform' => 'Uniform Fee',
            'transport' => 'Transport Fee',
            'library' => 'Library Fee',
            'sports' => 'Sports Fee',
            'other' => 'Miscellaneous Fee',
        ];
        
        $base = $descriptions[$data['payment_type']] ?? 'Fee Payment';
        
        if ($term) {
            $base .= ' - ' . $term->name . ' ' . $term->academicSession->name;
        }
        
        if ($student->class) {
            $base .= ' - ' . $student->class->name;
        }
        
        return $base;
    }

    /**
     * Calculate invoice amount
     */
    private function calculateInvoiceAmount(array $data, Student $student): float
    {
        if (isset($data['amount'])) {
            return (float) $data['amount'];
        }
        
        // Calculate based on class fee structure
        if ($student->class && $student->class->classLevel) {
            return $student->class->classLevel->fee_amount ?? 0;
        }
        
        return 0;
    }

    /**
     * Get monthly collection data
     */
    private function getMonthlyCollection(School $school, array $filters): array
    {
        $months = [];
        
        for ($i = 0; $i < 6; $i++) {
            $date = now()->subMonths($i);
            $start = $date->copy()->startOfMonth();
            $end = $date->copy()->endOfMonth();
            
            $amount = $school->payments()
                ->where('status', 'paid')
                ->whereBetween('payment_date', [$start, $end])
                ->sum('amount_paid');
            
            $months[] = [
                'month' => $date->format('M Y'),
                'amount' => $amount,
            ];
        }
        
        return array_reverse($months);
    }

    /**
     * Get overdue invoices
     */
    private function getOverdueInvoices(School $school, array $filters): array
    {
        return $school->payments()
            ->where('status', 'pending')
            ->where('due_date', '<', now())
            ->with('student.user')
            ->orderBy('due_date')
            ->limit(20)
            ->get()
            ->map(function ($payment) {
                return [
                    'invoice_number' => $payment->invoice_number,
                    'student' => $payment->student->full_name,
                    'amount' => $payment->balance,
                    'due_date' => $payment->due_date->format('d/m/Y'),
                    'days_overdue' => now()->diffInDays($payment->due_date),
                ];
            })
            ->toArray();
    }

    /**
     * Get payment breakdown
     */
    private function getPaymentBreakdown(Payment $payment): array
    {
        $details = $payment->payment_details ?? [];
        
        if (empty($details['payments'])) {
            return [
                [
                    'date' => $payment->payment_date?->format('d/m/Y') ?? 'N/A',
                    'amount' => $payment->amount_paid,
                    'method' => $payment->payment_method ?? 'N/A',
                    'reference' => $payment->transaction_id ?? 'N/A',
                ]
            ];
        }
        
        return array_map(function ($paymentRecord) {
            return [
                'date' => date('d/m/Y', strtotime($paymentRecord['date'])),
                'amount' => $paymentRecord['amount'],
                'method' => $paymentRecord['method'] ?? 'N/A',
                'reference' => $paymentRecord['reference'] ?? 'N/A',
            ];
        }, $details['payments']);
    }

    /**
     * Send invoice notification
     */
    private function sendInvoiceNotification(Payment $payment): void
    {
        // Implementation for sending email/SMS notification
        // This would integrate with your notification service
        $notificationService = new NotificationService();
        $notificationService->sendInvoice($payment);
    }

    /**
     * Send payment confirmation
     */
    private function sendPaymentConfirmation(Payment $payment, float $amount): void
    {
        $notificationService = new NotificationService();
        $notificationService->sendPaymentConfirmation($payment, $amount);
    }

    /**
     * Send refund notification
     */
    private function sendRefundNotification(Payment $payment, float $amount, string $reason): void
    {
        $notificationService = new NotificationService();
        $notificationService->sendRefundNotification($payment, $amount, $reason);
    }

    /**
     * Send reminder notification
     */
    private function sendReminderNotification(Payment $payment): void
    {
        $notificationService = new NotificationService();
        $notificationService->sendPaymentReminder($payment);
    }
}