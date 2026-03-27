<?php

namespace App\Services;

use App\Models\School;
use App\Models\Student;
use App\Models\User;
use App\Models\Teacher;
use App\Models\Classes;
use App\Models\Subject;
use App\Models\Payment;
use App\Models\Attendance;
use App\Events\GradeAssigned;
use App\Events\StudentAttended;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Facades\Excel;

class ImportService
{
    /**
     * Import students from Excel file
     */
    public function importStudents(School $school, $file): array
    {
        $data = Excel::toArray(new \stdClass(), $file)[0];
        $headers = array_shift($data);
        
        $results = [
            'total' => count($data),
            'successful' => 0,
            'failed' => 0,
            'errors' => [],
            'imported' => [],
        ];
        
        DB::beginTransaction();
        
        try {
            foreach ($data as $index => $row) {
                $rowData = array_combine($headers, $row);
                
                $validation = $this->validateStudentData($rowData, $school);
                
                if ($validation->fails()) {
                    $results['failed']++;
                    $results['errors'][] = [
                        'row' => $index + 2, // +2 for header and 1-based index
                        'data' => $rowData,
                        'errors' => $validation->errors()->all(),
                    ];
                    continue;
                }
                
                $student = $this->createStudent($school, $rowData);
                
                if ($student) {
                    $results['successful']++;
                    $results['imported'][] = [
                        'admission_number' => $student->admission_number,
                        'name' => $student->full_name,
                        'class' => $student->class->name ?? 'Not Assigned',
                    ];
                } else {
                    $results['failed']++;
                    $results['errors'][] = [
                        'row' => $index + 2,
                        'data' => $rowData,
                        'errors' => ['Failed to create student'],
                    ];
                }
            }
            
            DB::commit();
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            $results['errors'][] = [
                'row' => 'All',
                'errors' => ['Import failed: ' . $e->getMessage()],
            ];
        }
        
        return $results;
    }

    /**
     * Import teachers from Excel file
     */
    public function importTeachers(School $school, $file): array
    {
        $data = Excel::toArray(new \stdClass(), $file)[0];
        $headers = array_shift($data);
        
        $results = [
            'total' => count($data),
            'successful' => 0,
            'failed' => 0,
            'errors' => [],
            'imported' => [],
        ];
        
        DB::beginTransaction();
        
        try {
            foreach ($data as $index => $row) {
                $rowData = array_combine($headers, $row);
                
                $validation = $this->validateTeacherData($rowData, $school);
                
                if ($validation->fails()) {
                    $results['failed']++;
                    $results['errors'][] = [
                        'row' => $index + 2,
                        'data' => $rowData,
                        'errors' => $validation->errors()->all(),
                    ];
                    continue;
                }
                
                $teacher = $this->createTeacher($school, $rowData);
                
                if ($teacher) {
                    $results['successful']++;
                    $results['imported'][] = [
                        'teacher_id' => $teacher->teacher_id,
                        'name' => $teacher->full_name,
                        'email' => $teacher->email,
                        'subjects' => $teacher->subjects_expertise ?? [],
                    ];
                } else {
                    $results['failed']++;
                    $results['errors'][] = [
                        'row' => $index + 2,
                        'data' => $rowData,
                        'errors' => ['Failed to create teacher'],
                    ];
                }
            }
            
            DB::commit();
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            $results['errors'][] = [
                'row' => 'All',
                'errors' => ['Import failed: ' . $e->getMessage()],
            ];
        }
        
        return $results;
    }

    /**
     * Import results from Excel file
     */
    public function importResults(School $school, $file, int $termId): array
    {
        $data = Excel::toArray(new \stdClass(), $file)[0];
        $headers = array_shift($data);
        
        $results = [
            'total' => count($data),
            'successful' => 0,
            'failed' => 0,
            'errors' => [],
            'imported' => [],
        ];
        
        $term = \App\Models\TermSemester::findOrFail($termId);
        
        DB::beginTransaction();
        
        try {
            foreach ($data as $index => $row) {
                $rowData = array_combine($headers, $row);
                
                $validation = $this->validateResultData($rowData, $school, $term);
                
                if ($validation->fails()) {
                    $results['failed']++;
                    $results['errors'][] = [
                        'row' => $index + 2,
                        'data' => $rowData,
                        'errors' => $validation->errors()->all(),
                    ];
                    continue;
                }
                
                $result = $this->createResult($school, $term, $rowData);
                
                if ($result) {
                    $results['successful']++;
                    $results['imported'][] = [
                        'student' => $result->student->full_name,
                        'subject' => $result->subject->name,
                        'marks' => "{$result->marks_obtained}/{$result->total_marks}",
                        'grade' => $result->grade,
                    ];
                } else {
                    $results['failed']++;
                    $results['errors'][] = [
                        'row' => $index + 2,
                        'data' => $rowData,
                        'errors' => ['Failed to create result'],
                    ];
                }
            }
            
            DB::commit();
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            $results['errors'][] = [
                'row' => 'All',
                'errors' => ['Import failed: ' . $e->getMessage()],
            ];
        }
        
        return $results;
    }

    /**
     * Import payments from Excel file
     */
    public function importPayments(School $school, $file): array
    {
        $data = Excel::toArray(new \stdClass(), $file)[0];
        $headers = array_shift($data);
        
        $results = [
            'total' => count($data),
            'successful' => 0,
            'failed' => 0,
            'errors' => [],
            'imported' => [],
        ];
        
        DB::beginTransaction();
        
        try {
            foreach ($data as $index => $row) {
                $rowData = array_combine($headers, $row);
                
                $validation = $this->validatePaymentData($rowData, $school);
                
                if ($validation->fails()) {
                    $results['failed']++;
                    $results['errors'][] = [
                        'row' => $index + 2,
                        'data' => $rowData,
                        'errors' => $validation->errors()->all(),
                    ];
                    continue;
                }
                
                $payment = $this->createPayment($school, $rowData);
                
                if ($payment) {
                    $results['successful']++;
                    $results['imported'][] = [
                        'invoice_number' => $payment->invoice_number,
                        'student' => $payment->student->full_name,
                        'amount' => $payment->amount,
                        'status' => $payment->status,
                    ];
                } else {
                    $results['failed']++;
                    $results['errors'][] = [
                        'row' => $index + 2,
                        'data' => $rowData,
                        'errors' => ['Failed to create payment'],
                    ];
                }
            }
            
            DB::commit();
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            $results['errors'][] = [
                'row' => 'All',
                'errors' => ['Import failed: ' . $e->getMessage()],
            ];
        }
        
        return $results;
    }

    /**
     * Import attendance from Excel file
     */
    public function importAttendance(School $school, $file): array
    {
        $data = Excel::toArray(new \stdClass(), $file)[0];
        $headers = array_shift($data);
        
        $results = [
            'total' => count($data),
            'successful' => 0,
            'failed' => 0,
            'errors' => [],
            'imported' => [],
        ];
        
        DB::beginTransaction();
        
        try {
            foreach ($data as $index => $row) {
                $rowData = array_combine($headers, $row);
                
                $validation = $this->validateAttendanceData($rowData, $school);
                
                if ($validation->fails()) {
                    $results['failed']++;
                    $results['errors'][] = [
                        'row' => $index + 2,
                        'data' => $rowData,
                        'errors' => $validation->errors()->all(),
                    ];
                    continue;
                }
                
                $attendance = $this->createAttendance($school, $rowData);
                
                if ($attendance) {
                    $results['successful']++;
                    $results['imported'][] = [
                        'date' => $this->formatDate($attendance->attendance_date),
                        'student' => $attendance->student->full_name,
                        'status' => $attendance->status,
                    ];
                } else {
                    $results['failed']++;
                    $results['errors'][] = [
                        'row' => $index + 2,
                        'data' => $rowData,
                        'errors' => ['Failed to create attendance record'],
                    ];
                }
            }
            
            DB::commit();
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            $results['errors'][] = [
                'row' => 'All',
                'errors' => ['Import failed: ' . $e->getMessage()],
            ];
        }
        
        return $results;
    }

    /**
     * Import subjects from Excel file
     */
    public function importSubjects(School $school, $file): array
    {
        $data = Excel::toArray(new \stdClass(), $file)[0];
        $headers = array_shift($data);
        
        $results = [
            'total' => count($data),
            'successful' => 0,
            'failed' => 0,
            'errors' => [],
            'imported' => [],
        ];
        
        DB::beginTransaction();
        
        try {
            foreach ($data as $index => $row) {
                $rowData = array_combine($headers, $row);
                
                $validation = $this->validateSubjectData($rowData, $school);
                
                if ($validation->fails()) {
                    $results['failed']++;
                    $results['errors'][] = [
                        'row' => $index + 2,
                        'data' => $rowData,
                        'errors' => $validation->errors()->all(),
                    ];
                    continue;
                }
                
                $subject = $this->createSubject($school, $rowData);
                
                if ($subject) {
                    $results['successful']++;
                    $results['imported'][] = [
                        'code' => $subject->code,
                        'name' => $subject->name,
                        'type' => $subject->type,
                    ];
                } else {
                    $results['failed']++;
                    $results['errors'][] = [
                        'row' => $index + 2,
                        'data' => $rowData,
                        'errors' => ['Failed to create subject'],
                    ];
                }
            }
            
            DB::commit();
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            $results['errors'][] = [
                'row' => 'All',
                'errors' => ['Import failed: ' . $e->getMessage()],
            ];
        }
        
        return $results;
    }

    /**
     * Validate student import data
     */
    private function validateStudentData(array $data, School $school): \Illuminate\Contracts\Validation\Validator
    {
        return Validator::make($data, [
            'first_name' => 'required|string|max:100',
            'last_name' => 'required|string|max:100',
            'email' => 'required|email|unique:users,email',
            'date_of_birth' => 'required|date',
            'gender' => 'required|in:male,female,other',
            'admission_number' => 'required|unique:students,admission_number',
            'admission_date' => 'required|date',
            'class_code' => 'required|exists:classes,code,school_id,' . $school->id,
            'phone' => 'nullable|string',
            'address' => 'nullable|string',
            'emergency_contact_name' => 'required|string',
            'emergency_contact_phone' => 'required|string',
        ]);
    }

    /**
     * Create student from imported data
     */
    private function createStudent(School $school, array $data): ?Student
    {
        $class = Classes::where('code', $data['class_code'])
            ->where('school_id', $school->id)
            ->first();
            
        if (!$class) {
            return null;
        }
        
        // Create user account
        $user = User::create([
            'name' => $data['first_name'] . ' ' . $data['last_name'],
            'email' => $data['email'],
            'password' => Hash::make('password123'), // Default password
            'school_id' => $school->id,
            'user_type' => 'student',
            'phone' => $data['phone'] ?? null,
            'gender' => $data['gender'],
            'date_of_birth' => $data['date_of_birth'],
            'address' => $data['address'] ?? null,
            'status' => 'active',
        ]);
        
        // Create student record
        $student = Student::create([
            'user_id' => $user->id,
            'school_id' => $school->id,
            'class_id' => $class->id,
            'admission_number' => $data['admission_number'],
            'admission_date' => $data['admission_date'],
            'emergency_contact_name' => $data['emergency_contact_name'],
            'emergency_contact_phone' => $data['emergency_contact_phone'],
            'admission_type' => 'new',
            'student_category' => 'day',
        ]);
        
        // Update class student count
        $class->update([
            'student_count' => $class->students()->count()
        ]);
        
        return $student;
    }

    /**
     * Validate teacher import data
     */
    private function validateTeacherData(array $data, School $school): \Illuminate\Contracts\Validation\Validator
    {
        return Validator::make($data, [
            'name' => 'required|string|max:200',
            'email' => 'required|email|unique:users,email',
            'teacher_id' => 'required|unique:teachers,teacher_id',
            'employment_type' => 'required|in:permanent,contract,part_time,volunteer',
            'employment_date' => 'required|date',
            'phone' => 'nullable|string',
            'qualification' => 'nullable|string',
            'specialization' => 'nullable|string',
            'subjects' => 'nullable|string', // Comma-separated subject codes
        ]);
    }

    /**
     * Create teacher from imported data
     */
    private function createTeacher(School $school, array $data): ?Teacher
    {
        // Create user account
        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make('password123'),
            'school_id' => $school->id,
            'user_type' => 'teacher',
            'phone' => $data['phone'] ?? null,
            'status' => 'active',
        ]);
        
        // Create teacher record
        $teacher = Teacher::create([
            'user_id' => $user->id,
            'school_id' => $school->id,
            'teacher_id' => $data['teacher_id'],
            'employment_type' => $data['employment_type'],
            'employment_date' => $data['employment_date'],
            'qualification' => $data['qualification'] ?? null,
            'specialization' => $data['specialization'] ?? null,
        ]);
        
        // Assign subjects if provided
        if (!empty($data['subjects'])) {
            $subjectCodes = explode(',', $data['subjects']);
            $subjectIds = Subject::whereIn('code', array_map('trim', $subjectCodes))
                ->where('school_id', $school->id)
                ->pluck('id');
                
            $teacher->subjects()->sync($subjectIds);
        }
        
        return $teacher;
    }

    /**
     * Validate result import data
     */
    private function validateResultData(array $data, School $school, $term): \Illuminate\Contracts\Validation\Validator
    {
        return Validator::make($data, [
            'admission_number' => 'required|exists:students,admission_number,school_id,' . $school->id,
            'subject_code' => 'required|exists:subjects,code,school_id,' . $school->id,
            'marks_obtained' => 'required|numeric|min:0',
            'total_marks' => 'required|numeric|min:0|gte:marks_obtained',
            'teacher_comment' => 'nullable|string',
        ]);
    }

    /**
     * Create result from imported data
     */
    private function createResult(School $school, $term, array $data): ?\App\Models\Result
    {
        $student = Student::where('admission_number', $data['admission_number'])
            ->where('school_id', $school->id)
            ->first();
            
        $subject = Subject::where('code', $data['subject_code'])
            ->where('school_id', $school->id)
            ->first();
            
        if (!$student || !$subject) {
            return null;
        }
        
        $percentage = ($data['marks_obtained'] / $data['total_marks']) * 100;
        
        $result = \App\Models\Result::create([
            'school_id' => $school->id,
            'student_id' => $student->id,
            'class_id' => $student->class_id,
            'subject_id' => $subject->id,
            'academic_session_id' => $term->academic_session_id,
            'term_id' => $term->id,
            'marks_obtained' => $data['marks_obtained'],
            'total_marks' => $data['total_marks'],
            'percentage' => $percentage,
            'grade' => $this->calculateGrade($percentage),
            'grade_point' => $this->calculateGradePoint($percentage),
            'teacher_comment' => $data['teacher_comment'] ?? null,
            'is_finalized' => true,
            'graded_by' => Auth::id(),
        ]);

        event(new GradeAssigned($result));

        return $result;
    }

    /**
     * Calculate grade from percentage
     */
    private function calculateGrade(float $percentage): string
    {
        if ($percentage >= 70) return 'A';
        if ($percentage >= 60) return 'B';
        if ($percentage >= 50) return 'C';
        if ($percentage >= 45) return 'D';
        if ($percentage >= 40) return 'E';
        return 'F';
    }

    /**
     * Calculate grade point from percentage
     */
    private function calculateGradePoint(float $percentage): float
    {
        if ($percentage >= 70) return 5.0;
        if ($percentage >= 60) return 4.0;
        if ($percentage >= 50) return 3.0;
        if ($percentage >= 45) return 2.0;
        if ($percentage >= 40) return 1.0;
        return 0.0;
    }

    /**
     * Validate payment import data
     */
    private function validatePaymentData(array $data, School $school): \Illuminate\Contracts\Validation\Validator
    {
        return Validator::make($data, [
            'admission_number' => 'required|exists:students,admission_number,school_id,' . $school->id,
            'payment_type' => 'required|string|max:100',
            'description' => 'nullable|string',
            'amount' => 'required|numeric|min:0.01',
            'due_date' => 'nullable|date',
            'payment_method' => 'nullable|string|max:100',
        ]);
    }

    /**
     * Create payment from imported data
     */
    private function createPayment(School $school, array $data): ?Payment
    {
        $student = Student::where('admission_number', $data['admission_number'])
            ->where('school_id', $school->id)
            ->first();

        if (!$student) {
            return null;
        }

        $paymentService = new PaymentService();

        return $paymentService->createInvoice([
            'school_id' => $school->id,
            'student_id' => $student->id,
            'payment_type' => $data['payment_type'],
            'description' => $data['description'] ?? null,
            'amount' => (float) $data['amount'],
            'due_date' => $data['due_date'] ?? now()->addDays(30),
            'notes' => $data['notes'] ?? null,
            'send_notification' => false,
        ]);
    }

    /**
     * Validate attendance import data
     */
    private function validateAttendanceData(array $data, School $school): \Illuminate\Contracts\Validation\Validator
    {
        return Validator::make($data, [
            'admission_number' => 'required|exists:students,admission_number,school_id,' . $school->id,
            'attendance_date' => 'required|date',
            'status' => 'required|in:present,absent,late,excused',
            'reason' => 'nullable|string',
            'class_code' => 'nullable|exists:classes,code,school_id,' . $school->id,
        ]);
    }

    /**
     * Create attendance record from imported data
     */
    private function createAttendance(School $school, array $data): ?Attendance
    {
        $student = Student::where('admission_number', $data['admission_number'])
            ->where('school_id', $school->id)
            ->first();

        if (!$student) {
            return null;
        }

        $currentSession = $school->currentAcademicSession;
        $currentTerm = $currentSession ? $currentSession->currentTerm() : null;

        $existing = Attendance::where('school_id', $school->id)
            ->where('student_id', $student->id)
            ->where('attendance_date', $data['attendance_date'])
            ->first();

        if ($existing) {
            return $existing;
        }

        $attendance = Attendance::create([
            'school_id' => $school->id,
            'student_id' => $student->id,
            'class_id' => $student->class_id,
            'academic_session_id' => $currentSession?->id,
            'term_id' => $currentTerm?->id,
            'attendance_date' => $data['attendance_date'],
            'status' => $data['status'],
            'reason' => $data['reason'] ?? null,
            'recorded_by' => Auth::id(),
        ]);

        event(new StudentAttended($attendance));

        return $attendance;
    }

    /**
     * Validate subject import data
     */
    private function validateSubjectData(array $data, School $school): \Illuminate\Contracts\Validation\Validator
    {
        return Validator::make($data, [
            'name' => 'required|string|max:200',
            'code' => 'required|string|max:20|unique:subjects,code,NULL,id,school_id,' . $school->id,
            'short_name' => 'nullable|string|max:50',
            'type' => 'required|in:core,elective',
            'position' => 'nullable|integer|min:1',
            'has_practical' => 'nullable|boolean',
            'max_score' => 'nullable|numeric|min:0',
            'pass_score' => 'nullable|numeric|min:0',
            'description' => 'nullable|string',
        ]);
    }

    /**
     * Create subject from imported data
     */
    private function createSubject(School $school, array $data): ?Subject
    {
        return Subject::create([
            'school_id' => $school->id,
            'name' => $data['name'],
            'code' => $data['code'],
            'short_name' => $data['short_name'] ?? null,
            'type' => $data['type'],
            'position' => $data['position'] ?? null,
            'has_practical' => filter_var($data['has_practical'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'max_score' => $data['max_score'] ?? null,
            'pass_score' => $data['pass_score'] ?? null,
            'description' => $data['description'] ?? null,
        ]);
    }

    /**
     * Generate template for import
     */
    public function generateTemplate(string $type): string
    {
        $templates = [
            'students' => [
                ['first_name', 'last_name', 'email', 'date_of_birth', 'gender', 'admission_number', 'admission_date', 'class_code', 'phone', 'address', 'emergency_contact_name', 'emergency_contact_phone'],
                ['John', 'Doe', 'john@example.com', '2010-05-15', 'male', 'STU2024001', '2024-09-01', 'PRI5A', '08012345678', '123 Main St', 'Jane Doe', '08098765432'],
            ],
            'teachers' => [
                ['name', 'email', 'teacher_id', 'employment_type', 'employment_date', 'phone', 'qualification', 'specialization', 'subjects'],
                ['Sarah Smith', 'sarah@example.com', 'TCH2024001', 'permanent', '2024-01-15', '08011112222', 'B.Sc Education', 'Mathematics', 'MATH,PHY'],
            ],
            'results' => [
                ['admission_number', 'subject_code', 'marks_obtained', 'total_marks', 'teacher_comment'],
                ['STU2024001', 'MATH', '75', '100', 'Excellent work'],
            ],
            'payments' => [
                ['admission_number', 'payment_type', 'description', 'amount', 'due_date', 'payment_method'],
                ['STU2024001', 'tuition', 'First Term Fees', '50000', '2024-10-31', 'bank_transfer'],
            ],
            'attendance' => [
                ['admission_number', 'attendance_date', 'status', 'reason', 'class_code'],
                ['STU2024001', '2024-10-01', 'present', '', 'PRI5A'],
            ],
            'subjects' => [
                ['name', 'code', 'short_name', 'type', 'position', 'has_practical', 'max_score', 'pass_score', 'description'],
                ['Mathematics', 'MATH', 'MATH', 'core', '1', '0', '100', '40', 'Core mathematics'],
            ],
        ];
        
        if (!isset($templates[$type])) {
            throw new \InvalidArgumentException("Invalid template type: {$type}");
        }
        
        $filename = "{$type}_template_" . now()->format('Ymd_His') . '.csv';
        $path = Storage::path('templates/' . $filename);
        
        $file = fopen($path, 'w');
        
        foreach ($templates[$type] as $row) {
            fputcsv($file, $row);
        }
        
        fclose($file);
        
        return $path;
    }

    private function formatDate($value, string $format = 'd/m/Y'): ?string
    {
        if (!$value) {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format($format);
        }

        if (is_string($value) || is_numeric($value)) {
            return \Carbon\Carbon::parse($value)->format($format);
        }

        return null;
    }
}
