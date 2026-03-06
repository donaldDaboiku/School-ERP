<?php

namespace App\Services;

use App\Models\User;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\Payment;
use App\Models\Notice;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class NotificationService
{
    /**
     * Send welcome email to new user
     */
    public function sendWelcomeEmail(User $user, string $password = null): bool
    {
        try {
            Mail::send('emails.welcome', [
                'user' => $user,
                'password' => $password,
                'school' => $user->school,
            ], function ($message) use ($user) {
                $message->to($user->email)
                    ->subject('Welcome to ' . ($user->school->name ?? 'School Management System'));
            });
            
            Log::channel('notification')->info('Welcome email sent', [
                'user_id' => $user->id,
                'email' => $user->email,
            ]);
            
            return true;
            
        } catch (\Exception $e) {
            Log::channel('notification')->error('Failed to send welcome email', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
            
            return false;
        }
    }

    /**
     * Send password reset email
     */
    public function sendPasswordReset(User $user, string $token): bool
    {
        try {
            Mail::send('emails.password-reset', [
                'user' => $user,
                'token' => $token,
                'reset_url' => url('/password/reset/' . $token),
            ], function ($message) use ($user) {
                $message->to($user->email)
                    ->subject('Password Reset Request');
            });
            
            Log::channel('notification')->info('Password reset email sent', [
                'user_id' => $user->id,
                'email' => $user->email,
            ]);
            
            return true;
            
        } catch (\Exception $e) {
            Log::channel('notification')->error('Failed to send password reset email', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
            
            return false;
        }
    }

    /**
     * Send student admission notification
     */
    public function sendStudentAdmissionNotification(Student $student): bool
    {
        try {
            $user = $student->user;
            $parents = $student->parents;
            
            // Send to student
            Mail::send('emails.student-admission', [
                'student' => $student,
                'user' => $user,
                'school' => $student->school,
            ], function ($message) use ($user) {
                $message->to($user->email)
                    ->subject('Student Admission Confirmation');
            });
            
            // Send to parents
            foreach ($parents as $parent) {
                if ($parent->user->email) {
                    Mail::send('emails.parent-admission', [
                        'student' => $student,
                        'parent' => $parent,
                        'school' => $student->school,
                    ], function ($message) use ($parent) {
                        $message->to($parent->user->email)
                            ->subject('Your Child\'s Admission Confirmation');
                    });
                }
            }
            
            Log::channel('notification')->info('Student admission notifications sent', [
                'student_id' => $student->id,
                'student_email' => $user->email,
                'parent_count' => $parents->count(),
            ]);
            
            return true;
            
        } catch (\Exception $e) {
            Log::channel('notification')->error('Failed to send admission notifications', [
                'student_id' => $student->id,
                'error' => $e->getMessage(),
            ]);
            
            return false;
        }
    }

    /**
     * Send invoice notification
     */
    public function sendInvoice(Payment $payment): bool
    {
        try {
            $student = $payment->student;
            $parents = $student->parents;
            
            $data = [
                'payment' => $payment,
                'student' => $student,
                'school' => $payment->school,
                'due_date' => $payment->due_date->format('d/m/Y'),
            ];
            
            // Send to parents
            foreach ($parents as $parent) {
                if ($parent->user->email && $parent->receives_notifications) {
                    Mail::send('emails.invoice', $data, function ($message) use ($parent, $payment) {
                        $message->to($parent->user->email)
                            ->subject('Fee Invoice: ' . $payment->invoice_number);
                    });
                }
            }
            
            // Also send to student if they have email
            if ($student->email) {
                Mail::send('emails.invoice', $data, function ($message) use ($student, $payment) {
                    $message->to($student->email)
                        ->subject('Fee Invoice: ' . $payment->invoice_number);
                });
            }
            
            Log::channel('notification')->info('Invoice notification sent', [
                'payment_id' => $payment->id,
                'invoice_number' => $payment->invoice_number,
                'amount' => $payment->amount,
            ]);
            
            return true;
            
        } catch (\Exception $e) {
            Log::channel('notification')->error('Failed to send invoice notification', [
                'payment_id' => $payment->id,
                'error' => $e->getMessage(),
            ]);
            
            return false;
        }
    }

    /**
     * Send payment confirmation
     */
    public function sendPaymentConfirmation(Payment $payment, float $amount): bool
    {
        try {
            $student = $payment->student;
            $parents = $student->parents;
            
            $data = [
                'payment' => $payment,
                'student' => $student,
                'school' => $payment->school,
                'amount_paid' => $amount,
                'receipt_number' => $payment->receipt_number,
                'payment_date' => $payment->payment_date->format('d/m/Y'),
            ];
            
            // Send to parents
            foreach ($parents as $parent) {
                if ($parent->user->email && $parent->receives_notifications) {
                    Mail::send('emails.payment-confirmation', $data, function ($message) use ($parent, $payment) {
                        $message->to($parent->user->email)
                            ->subject('Payment Confirmation: ' . $payment->invoice_number);
                    });
                }
            }
            
            // Send to school admin/accountant
            $admins = User::where('school_id', $payment->school_id)
                ->whereIn('user_type', ['admin', 'accountant'])
                ->where('status', 'active')
                ->get();
            
            foreach ($admins as $admin) {
                Mail::send('emails.payment-received', $data, function ($message) use ($admin, $payment) {
                    $message->to($admin->email)
                        ->subject('Payment Received: ' . $payment->invoice_number);
                });
            }
            
            Log::channel('notification')->info('Payment confirmation sent', [
                'payment_id' => $payment->id,
                'invoice_number' => $payment->invoice_number,
                'amount' => $amount,
            ]);
            
            return true;
            
        } catch (\Exception $e) {
            Log::channel('notification')->error('Failed to send payment confirmation', [
                'payment_id' => $payment->id,
                'error' => $e->getMessage(),
            ]);
            
            return false;
        }
    }

    /**
     * Send payment reminder
     */
    public function sendPaymentReminder(Payment $payment): bool
    {
        try {
            $student = $payment->student;
            $parents = $student->parents;
            
            $daysOverdue = now()->diffInDays($payment->due_date);
            
            $data = [
                'payment' => $payment,
                'student' => $student,
                'school' => $payment->school,
                'due_date' => $payment->due_date->format('d/m/Y'),
                'days_overdue' => $daysOverdue,
                'balance' => $payment->balance,
            ];
            
            $sent = false;
            
            // Send to parents
            foreach ($parents as $parent) {
                if ($parent->user->email && $parent->receives_notifications) {
                    Mail::send('emails.payment-reminder', $data, function ($message) use ($parent, $payment, $daysOverdue) {
                        $subject = $daysOverdue > 0 
                            ? 'Overdue Payment Reminder: ' . $payment->invoice_number
                            : 'Payment Due Soon: ' . $payment->invoice_number;
                        
                        $message->to($parent->user->email)->subject($subject);
                    });
                    $sent = true;
                }
            }
            
            if ($sent) {
                Log::channel('notification')->info('Payment reminder sent', [
                    'payment_id' => $payment->id,
                    'invoice_number' => $payment->invoice_number,
                    'days_overdue' => $daysOverdue,
                ]);
            }
            
            return $sent;
            
        } catch (\Exception $e) {
            Log::channel('notification')->error('Failed to send payment reminder', [
                'payment_id' => $payment->id,
                'error' => $e->getMessage(),
            ]);
            
            return false;
        }
    }

    /**
     * Send exam results notification
     */
    public function sendExamResults(int $termId, array $studentIds = []): array
    {
        $academicService = new AcademicService();
        $reportCards = $academicService->generateReportCards($termId, $studentIds);
        
        $results = [
            'total' => count($reportCards),
            'sent' => 0,
            'failed' => 0,
            'details' => [],
        ];
        
        foreach ($reportCards as $reportCard) {
            try {
                $student = Student::find($reportCard['student']['id']);
                $parents = $student->parents;
                
                $term = \App\Models\TermSemester::find($termId);
                
                $data = [
                    'report_card' => $reportCard,
                    'student' => $student,
                    'term' => $term,
                    'school' => $student->school,
                ];
                
                // Send to parents
                foreach ($parents as $parent) {
                    if ($parent->user->email && $parent->receives_notifications) {
                        Mail::send('emails.exam-results', $data, function ($message) use ($parent, $student, $term) {
                            $message->to($parent->user->email)
                                ->subject($term->name . ' Results - ' . $student->full_name);
                        });
                    }
                }
                
                // Send to student if they have email
                if ($student->email) {
                    Mail::send('emails.exam-results-student', $data, function ($message) use ($student, $term) {
                        $message->to($student->email)
                            ->subject('Your ' . $term->name . ' Examination Results');
                    });
                }
                
                $results['sent']++;
                $results['details'][] = [
                    'student' => $student->full_name,
                    'status' => 'sent',
                ];
                
                Log::channel('notification')->info('Exam results sent', [
                    'student_id' => $student->id,
                    'term_id' => $termId,
                ]);
                
            } catch (\Exception $e) {
                $results['failed']++;
                $results['details'][] = [
                    'student' => $reportCard['student']['name'],
                    'status' => 'failed',
                    'error' => $e->getMessage(),
                ];
                
                Log::channel('notification')->error('Failed to send exam results', [
                    'student_id' => $reportCard['student']['id'],
                    'error' => $e->getMessage(),
                ]);
            }
        }
        
        return $results;
    }

    /**
     * Send attendance notification
     */
    public function sendAttendanceNotification(array $attendanceData): bool
    {
        try {
            $student = Student::find($attendanceData['student_id']);
            $parents = $student->parents;
            
            $data = [
                'student' => $student,
                'attendance' => $attendanceData,
                'school' => $student->school,
                'date' => $attendanceData['date']->format('d/m/Y'),
            ];
            
            // Send to parents
            foreach ($parents as $parent) {
                if ($parent->user->email && $parent->receives_notifications) {
                    Mail::send('emails.attendance', $data, function ($message) use ($parent, $attendanceData) {
                        $status = $attendanceData['status'];
                        $subject = 'Attendance ' . ucfirst($status) . ': ' . $attendanceData['date']->format('d/m/Y');
                        $message->to($parent->user->email)->subject($subject);
                    });
                }
            }
            
            Log::channel('notification')->info('Attendance notification sent', [
                'student_id' => $student->id,
                'attendance_date' => $attendanceData['date']->format('Y-m-d'),
                'status' => $attendanceData['status'],
            ]);
            
            return true;
            
        } catch (\Exception $e) {
            Log::channel('notification')->error('Failed to send attendance notification', [
                'student_id' => $attendanceData['student_id'],
                'error' => $e->getMessage(),
            ]);
            
            return false;
        }
    }

    /**
     * Send notice notification
     */
    public function sendNoticeNotification(Notice $notice): bool
    {
        try {
            $recipients = $this->getNoticeRecipients($notice);
            
            $data = [
                'notice' => $notice,
                'school' => $notice->school,
            ];
            
            $sentCount = 0;
            
            foreach ($recipients as $recipient) {
                if ($recipient['email']) {
                    Mail::send('emails.notice', $data, function ($message) use ($recipient, $notice) {
                        $message->to($recipient['email'])
                            ->subject('New Notice: ' . $notice->title);
                    });
                    $sentCount++;
                }
            }
            
            // Mark notice as notified
            $notice->markAsNotified();
            
            Log::channel('notification')->info('Notice notification sent', [
                'notice_id' => $notice->id,
                'recipients' => $sentCount,
                'target_audience' => $notice->target_audience,
            ]);
            
            return true;
            
        } catch (\Exception $e) {
            Log::channel('notification')->error('Failed to send notice notification', [
                'notice_id' => $notice->id,
                'error' => $e->getMessage(),
            ]);
            
            return false;
        }
    }

    /**
     * Send bulk SMS notifications
     */
    public function sendBulkSms(array $recipients, string $message): array
    {
        $results = [
            'total' => count($recipients),
            'sent' => 0,
            'failed' => 0,
            'details' => [],
        ];
        
        // This would integrate with an SMS gateway like Twilio, Africa's Talking, etc.
        // For now, we'll log the attempt
        
        foreach ($recipients as $recipient) {
            try {
                // Example: Integrate with SMS service
                // $smsService->send($recipient['phone'], $message);
                
                Log::channel('sms')->info('SMS sent', [
                    'phone' => $recipient['phone'],
                    'message' => substr($message, 0, 50) . '...',
                ]);
                
                $results['sent']++;
                $results['details'][] = [
                    'phone' => $recipient['phone'],
                    'status' => 'sent',
                ];
                
            } catch (\Exception $e) {
                $results['failed']++;
                $results['details'][] = [
                    'phone' => $recipient['phone'],
                    'status' => 'failed',
                    'error' => $e->getMessage(),
                ];
                
                Log::channel('sms')->error('Failed to send SMS', [
                    'phone' => $recipient['phone'],
                    'error' => $e->getMessage(),
                ]);
            }
        }
        
        return $results;
    }

    /**
     * Get recipients for notice
     */
    private function getNoticeRecipients(Notice $notice): array
    {
        $recipients = [];
        
        switch ($notice->target_audience) {
            case 'all':
                // All users in school
                $users = User::where('school_id', $notice->school_id)
                    ->where('status', 'active')
                    ->get();
                
                foreach ($users as $user) {
                    $recipients[] = [
                        'email' => $user->email,
                        'name' => $user->name,
                        'type' => $user->user_type,
                    ];
                }
                break;
                
            case 'students':
                $students = Student::where('school_id', $notice->school_id)
                    ->with('user')
                    ->get();
                
                foreach ($students as $student) {
                    if ($student->user && $student->user->email) {
                        $recipients[] = [
                            'email' => $student->user->email,
                            'name' => $student->full_name,
                            'type' => 'student',
                        ];
                    }
                }
                break;
                
            case 'teachers':
                $teachers = Teacher::where('school_id', $notice->school_id)
                    ->with('user')
                    ->get();
                
                foreach ($teachers as $teacher) {
                    if ($teacher->user && $teacher->user->email) {
                        $recipients[] = [
                            'email' => $teacher->user->email,
                            'name' => $teacher->full_name,
                            'type' => 'teacher',
                        ];
                    }
                }
                break;
                
            case 'parents':
                // Get all parents through students
                $students = Student::where('school_id', $notice->school_id)
                    ->with('parents.user')
                    ->get();
                
                foreach ($students as $student) {
                    foreach ($student->parents as $parent) {
                        if ($parent->user && $parent->user->email) {
                            $recipients[] = [
                                'email' => $parent->user->email,
                                'name' => $parent->user->name,
                                'type' => 'parent',
                            ];
                        }
                    }
                }
                break;
                
            case 'specific_class':
                if ($notice->target_classes && is_array($notice->target_classes)) {
                    $students = Student::whereIn('class_id', $notice->target_classes)
                        ->with(['user', 'parents.user'])
                        ->get();
                    
                    foreach ($students as $student) {
                        // Add student
                        if ($student->user && $student->user->email) {
                            $recipients[] = [
                                'email' => $student->user->email,
                                'name' => $student->full_name,
                                'type' => 'student',
                            ];
                        }
                        
                        // Add parents
                        foreach ($student->parents as $parent) {
                            if ($parent->user && $parent->user->email) {
                                $recipients[] = [
                                    'email' => $parent->user->email,
                                    'name' => $parent->user->name,
                                    'type' => 'parent',
                                ];
                            }
                        }
                    }
                }
                break;
        }
        
        // Remove duplicates by email
        $uniqueRecipients = [];
        $emails = [];
        
        foreach ($recipients as $recipient) {
            if (!in_array($recipient['email'], $emails)) {
                $emails[] = $recipient['email'];
                $uniqueRecipients[] = $recipient;
            }
        }
        
        return $uniqueRecipients;
    }
}