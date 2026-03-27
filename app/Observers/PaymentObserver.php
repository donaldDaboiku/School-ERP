<?php

namespace App\Observers;

use App\Models\Payment;
use Illuminate\Support\Facades\Log;

class PaymentObserver
{
    /**
     * Handle events after all transactions are committed.
     *
     * @var bool
     */
    public $afterCommit = true;

    public function creating(Payment $payment): void
    {
        try {
            $this->normalizeAmounts($payment);
        } catch (\Exception $e) {
            Log::error('Payment creating failed', [
                'error' => $e->getMessage(),
                'payment' => $payment->toArray(),
            ]);
            throw $e;
        }
    }

    public function updating(Payment $payment): void
    {
        try {
            if ($payment->isDirty(['amount', 'amount_paid', 'status'])) {
                $this->normalizeAmounts($payment);
            }
        } catch (\Exception $e) {
            Log::error('Payment updating failed', [
                'payment_id' => $payment->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function created(Payment $payment): void
    {
        Log::info('Payment created', [
            'payment_id' => $payment->id,
            'student_id' => $payment->student_id,
            'amount' => $payment->amount,
            'status' => $payment->status,
        ]);
    }

    public function deleted(Payment $payment): void
    {
        Log::warning('Payment deleted', [
            'payment_id' => $payment->id,
            'student_id' => $payment->student_id,
            'amount' => $payment->amount,
            'status' => $payment->status,
        ]);
    }

    private function normalizeAmounts(Payment $payment): void
    {
        $amount = (float) ($payment->amount ?? 0);
        $amountPaid = (float) ($payment->amount_paid ?? 0);

        if ($amountPaid < 0) {
            $amountPaid = 0;
        }

        $balance = max($amount - $amountPaid, 0);
        $computedStatus = 'pending';

        if ($amountPaid > 0 && $balance > 0) {
            $computedStatus = 'partial';
        } elseif ($balance <= 0 && $amount > 0) {
            $computedStatus = 'paid';
        }

        $allowedStatuses = ['pending', 'partial', 'paid', null, ''];

        if (in_array($payment->status, $allowedStatuses, true)) {
            $payment->status = $computedStatus;
        }

        $payment->amount_paid = $amountPaid;
        $payment->balance = $balance;

        if ($payment->status === 'paid' && empty($payment->payment_date)) {
            $payment->payment_date = now();
        }
    }
}
