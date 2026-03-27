<?php

namespace App\Services;

use App\Models\School;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\Result;
use App\Models\Payment;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use Barryvdh\DomPDF\Facade\Pdf;

class ExportService
{
    /**
     * Export students to Excel
     */
    public function exportStudents(School $school, array $filters = []): string
    {
        $query = $school->students()->with(['user', 'class']);
        
        if (isset($filters['class_id'])) {
            $query->where('class_id', $filters['class_id']);
        }
        
        if (isset($filters['status'])) {
            $query->whereHas('user', function ($q) use ($filters) {
                $q->where('status', $filters['status']);
            });
        }
        
        $students = $query->get();
        
        $data = $students->map(function ($student) {
            return [
                'Admission Number' => $student->admission_number,
                'Full Name' => $student->full_name,
                'Class' => $student->class->name ?? 'N/A',
                'Gender' => $student->gender ?? 'N/A',
                'Date of Birth' => $student->user->date_of_birth?->format('d/m/Y') ?? 'N/A',
                'Phone' => $student->phone ?? 'N/A',
                'Email' => $student->email,
                'Address' => $student->user->address ?? 'N/A',
                'Admission Date' => $student->admission_date?->format('d/m/Y') ?? 'N/A',
                'Status' => $student->is_active ? 'Active' : 'Inactive',
            ];
        });
        
        $filename = 'students_' . $school->code . '_' . now()->format('Ymd_His') . '.xlsx';
        $path = 'exports/' . $filename;
        
        Excel::store(new \App\Exports\StudentsExport($data), $path);
        
        return Storage::path($path);
    }

    /**
     * Export teachers to Excel
     */
    public function exportTeachers(School $school, array $filters = []): string
    {
        $query = $school->teachers()->with(['user', 'subjects']);
        
        if (isset($filters['status'])) {
            $query->whereHas('user', function ($q) use ($filters) {
                $q->where('status', $filters['status']);
            });
        }
        
        $teachers = $query->get();
        
        $data = $teachers->map(function ($teacher) {
            $subjects = $teacher->subjects->pluck('name')->implode(', ');
            
            return [
                'Teacher ID' => $teacher->teacher_id,
                'Full Name' => $teacher->full_name,
                'Email' => $teacher->email,
                'Phone' => $teacher->phone ?? 'N/A',
                'Qualification' => $teacher->qualification ?? 'N/A',
                'Specialization' => $teacher->specialization ?? 'N/A',
                'Employment Type' => ucfirst($teacher->employment_type),
                'Employment Date' => $teacher->employment_date?->format('d/m/Y') ?? 'N/A',
                'Subjects' => $subjects,
                'Is Class Teacher' => $teacher->is_class_teacher ? 'Yes' : 'No',
                'Status' => $teacher->is_active ? 'Active' : 'Inactive',
            ];
        });
        
        $filename = 'teachers_' . $school->code . '_' . now()->format('Ymd_His') . '.xlsx';
        $path = 'exports/' . $filename;
        
        Excel::store(new \App\Exports\TeachersExport($data), $path);
        
        return Storage::path($path);
    }

    /**
     * Export results to Excel
     */
    public function exportResults(int $termId, array $filters = []): string
    {
        $term = \App\Models\TermSemester::with('academicSession.school')->findOrFail($termId);
        $school = $term->academicSession->school;
        
        $query = Result::where('term_id', $termId)
            ->where('is_finalized', true)
            ->with(['student.user', 'subject', 'class']);
        
        if (isset($filters['class_id'])) {
            $query->where('class_id', $filters['class_id']);
        }
        
        if (isset($filters['subject_id'])) {
            $query->where('subject_id', $filters['subject_id']);
        }
        
        $results = $query->get()->groupBy(['class_id', 'student_id']);
        
        $data = [];
        foreach ($results as $classId => $classResults) {
            $class = \App\Models\Classes::find($classId);
            
            foreach ($classResults as $studentId => $studentResults) {
                $student = $studentResults->first()->student;
                
                foreach ($studentResults as $result) {
                    $data[] = [
                        'Class' => $class->name ?? 'N/A',
                        'Admission Number' => $student->admission_number,
                        'Student Name' => $student->full_name,
                        'Subject' => $result->subject->name,
                        'Marks Obtained' => $result->marks_obtained,
                        'Total Marks' => $result->total_marks,
                        'Percentage' => $result->percentage,
                        'Grade' => $result->grade,
                        'Grade Point' => $result->grade_point,
                        'Teacher Comment' => $result->teacher_comment,
                    ];
                }
            }
        }
        
        $filename = 'results_' . $school->code . '_' . $term->code . '_' . now()->format('Ymd_His') . '.xlsx';
        $path = 'exports/' . $filename;
        
        Excel::store(new \App\Exports\ResultsExport($data), $path);
        
        return Storage::path($path);
    }

    /**
     * Export payments to Excel
     */
    public function exportPayments(School $school, array $filters = []): string
    {
        $query = $school->payments()->with('student.user');
        
        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        
        if (isset($filters['start_date'])) {
            $query->where('created_at', '>=', $filters['start_date']);
        }
        
        if (isset($filters['end_date'])) {
            $query->where('created_at', '<=', $filters['end_date']);
        }
        
        $payments = $query->get();
        
        $data = $payments->map(function ($payment) {
            return [
                'Invoice Number' => $payment->invoice_number,
                'Student Name' => $payment->student->full_name,
                'Admission Number' => $payment->student->admission_number,
                'Payment Type' => ucfirst($payment->payment_type),
                'Description' => $payment->description,
                'Amount' => $payment->amount,
                'Amount Paid' => $payment->amount_paid,
                'Balance' => $payment->balance,
                'Due Date' => $payment->due_date?->format('d/m/Y') ?? 'N/A',
                'Payment Date' => $payment->payment_date?->format('d/m/Y') ?? 'N/A',
                'Status' => ucfirst($payment->status),
                'Payment Method' => $payment->payment_method ?? 'N/A',
                'Transaction ID' => $payment->transaction_id ?? 'N/A',
            ];
        });
        
        $filename = 'payments_' . $school->code . '_' . now()->format('Ymd_His') . '.xlsx';
        $path = 'exports/' . $filename;
        
        Excel::store(new \App\Exports\PaymentsExport($data), $path);
        
        return Storage::path($path);
    }

    /**
     * Export attendance to Excel
     */
    public function exportAttendance(School $school, array $filters = []): string
    {
        $query = $school->attendances()->with(['student.user', 'class']);
        
        if (isset($filters['start_date'])) {
            $query->where('attendance_date', '>=', $filters['start_date']);
        }
        
        if (isset($filters['end_date'])) {
            $query->where('attendance_date', '<=', $filters['end_date']);
        }
        
        if (isset($filters['class_id'])) {
            $query->where('class_id', $filters['class_id']);
        }
        
        $attendance = $query->get();
        
        $data = $attendance->map(function ($record) {
            return [
                'Date' => $record->attendance_date->format('d/m/Y'),
                'Class' => $record->class->name ?? 'N/A',
                'Admission Number' => $record->student->admission_number,
                'Student Name' => $record->student->full_name,
                'Status' => ucfirst($record->status),
                'Check-in Time' => $record->check_in_time?->format('h:i A') ?? 'N/A',
                'Check-out Time' => $record->check_out_time?->format('h:i A') ?? 'N/A',
                'Reason' => $record->reason ?? 'N/A',
                'Recorded By' => $record->recordedBy->name ?? 'System',
            ];
        });
        
        $filename = 'attendance_' . $school->code . '_' . now()->format('Ymd_His') . '.xlsx';
        $path = 'exports/' . $filename;
        
        Excel::store(new \App\Exports\AttendanceExport($data), $path);
        
        return Storage::path($path);
    }

    /**
     * Generate PDF report card
     */
    public function generateReportCardPdf(int $studentId, int $termId): string
    {
        $academicService = new AcademicService();
        $reportCard = $academicService->generateReportCards($termId, [$studentId])[0] ?? null;
        
        if (!$reportCard) {
            throw new \Exception('No report card found for student');
        }
        
        $pdf = Pdf::loadView('exports.report-card', [
            'reportCard' => $reportCard,
            'school' => Student::find($studentId)->school,
        ]);
        
        $filename = 'report_card_' . $reportCard['student']['admission_number'] . '_' . now()->format('Ymd_His') . '.pdf';
        $path = 'exports/' . $filename;
        
        Storage::put($path, $pdf->output());
        
        return Storage::path($path);
    }

    /**
     * Generate PDF transcript
     */
    public function generateTranscriptPdf(int $studentId): string
    {
        $academicService = new AcademicService();
        $transcript = $academicService->generateTranscript($studentId);
        
        $pdf = Pdf::loadView('exports.transcript', [
            'transcript' => $transcript,
            'school' => Student::find($studentId)->school,
        ]);
        
        $filename = 'transcript_' . $transcript['student']['admission_number'] . '_' . now()->format('Ymd_His') . '.pdf';
        $path = 'exports/' . $filename;
        
        Storage::put($path, $pdf->output());
        
        return Storage::path($path);
    }

    /**
     * Generate financial statement PDF
     */
    public function generateFinancialStatement(School $school, array $filters = []): string
    {
        $payments = $school->payments()
            ->when(isset($filters['start_date']), function ($q) use ($filters) {
                $q->where('payment_date', '>=', $filters['start_date']);
            })
            ->when(isset($filters['end_date']), function ($q) use ($filters) {
                $q->where('payment_date', '<=', $filters['end_date']);
            })
            ->get();
        
        $summary = [
            'total_amount' => $payments->sum('amount'),
            'total_paid' => $payments->where('status', 'paid')->sum('amount_paid'),
            'total_pending' => $payments->where('status', 'pending')->sum('amount'),
            'total_overdue' => $payments->where('status', 'pending')
                ->where('due_date', '<', now())
                ->sum('amount'),
        ];
        
        $pdf = Pdf::loadView('exports.financial-statement', [
            'school' => $school,
            'payments' => $payments,
            'summary' => $summary,
            'filters' => $filters,
        ]);
        
        $filename = 'financial_statement_' . $school->code . '_' . now()->format('Ymd_His') . '.pdf';
        $path = 'exports/' . $filename;
        
        Storage::put($path, $pdf->output());
        
        return Storage::path($path);
    }

    /**
     * Export to CSV format
     */
    public function exportToCsv(array $data, string $filename): string
    {
        $path = 'exports/' . $filename . '_' . now()->format('Ymd_His') . '.csv';
        
        $file = fopen(Storage::path($path), 'w');
        
        // Add headers
        if (!empty($data)) {
            fputcsv($file, array_keys($data[0]));
        }
        
        // Add data rows
        foreach ($data as $row) {
            fputcsv($file, $row);
        }
        
        fclose($file);
        
        return Storage::path($path);
    }

    /**
     * Export school data package (ZIP)
     */
    public function exportSchoolDataPackage(School $school): string
    {
        $timestamp = now()->format('Ymd_His');
        $zipFilename = 'school_data_' . $school->code . '_' . $timestamp . '.zip';
        $zipPath = Storage::path('exports/' . $zipFilename);
        
        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE);
        
        // Export students
        $studentsPath = $this->exportStudents($school);
        $zip->addFile($studentsPath, 'students.xlsx');
        
        // Export teachers
        $teachersPath = $this->exportTeachers($school);
        $zip->addFile($teachersPath, 'teachers.xlsx');
        
        // Export financial summary
        $financialPath = $this->generateFinancialStatement($school);
        $zip->addFile($financialPath, 'financial_statement.pdf');
        
        // Add school info file
        $schoolInfo = [
            'School Name' => $school->name,
            'School Code' => $school->code,
            'Address' => $school->address,
            'Phone' => $school->phone,
            'Email' => $school->email,
            'Total Students' => $school->students()->count(),
            'Total Teachers' => $school->teachers()->count(),
            'Total Classes' => $school->classes()->count(),
            'Export Date' => now()->format('Y-m-d H:i:s'),
        ];
        
        $infoPath = Storage::path('exports/school_info_' . $timestamp . '.json');
        file_put_contents($infoPath, json_encode($schoolInfo, JSON_PRETTY_PRINT));
        $zip->addFile($infoPath, 'school_info.json');
        
        $zip->close();
        
        // Clean up temporary files
        unlink($studentsPath);
        unlink($teachersPath);
        unlink($financialPath);
        unlink($infoPath);
        
        return $zipPath;
    }
}