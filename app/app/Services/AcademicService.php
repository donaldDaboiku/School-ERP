<?php

namespace App\Services;

use App\Models\AcademicSession;
use App\Models\TermSemester;
use App\Models\Classes;
use App\Models\Student;
use App\Models\Result;
use App\Models\Remark;
use App\Models\Attendance;
use App\Models\Assessment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class AcademicService
{
    /**
     * Create a new academic session
     */
    public function createAcademicSession(array $data): AcademicSession
    {
        return DB::transaction(function () use ($data) {
            // If setting as current, deactivate other current sessions
            if ($data['is_current'] ?? false) {
                AcademicSession::where('school_id', $data['school_id'])
                    ->where('is_current', true)
                    ->update(['is_current' => false]);
            }

            $session = AcademicSession::create([
                'school_id' => $data['school_id'],
                'name' => $data['name'],
                'code' => $this->generateSessionCode($data['name']),
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'],
                'is_current' => $data['is_current'] ?? false,
                'status' => $data['status'] ?? 'upcoming',
                'description' => $data['description'] ?? null,
            ]);

            // Create default terms if specified
            if ($data['create_terms'] ?? true) {
                $this->createDefaultTerms($session);
            }

            return $session;
        });
    }

    /**
     * Create term/semester for academic session
     */
    public function createTerm(array $data): TermSemester
    {
        return DB::transaction(function () use ($data) {
            // If setting as current, deactivate other current terms in same session
            if ($data['is_current'] ?? false) {
                TermSemester::where('academic_session_id', $data['academic_session_id'])
                    ->where('is_current', true)
                    ->update(['is_current' => false]);
            }

            return TermSemester::create([
                'school_id' => $data['school_id'],
                'academic_session_id' => $data['academic_session_id'],
                'name' => $data['name'],
                'code' => $this->generateTermCode($data['name']),
                'order' => $data['order'] ?? 1,
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'],
                'is_current' => $data['is_current'] ?? false,
                'status' => $data['status'] ?? 'upcoming',
                'term_fee' => $data['term_fee'] ?? null,
                'description' => $data['description'] ?? null,
            ]);
        });
    }

    /**
     * Calculate student results for term
     */
    public function calculateTermResults(int $termId, array $options = []): array
    {
        return DB::transaction(function () use ($termId, $options) {
            $term = TermSemester::with('academicSession.school')->findOrFail($termId);
            $school = $term->academicSession->school;
            
            $results = [];
            $classes = Classes::where('school_id', $school->id)
                ->where('term_id', $termId)
                ->get();

            foreach ($classes as $class) {
                $classResults = $this->calculateClassResults($class, $term, $options);
                $results[$class->id] = $classResults;
            }

            // Update term status if all results finalized
            if ($options['finalize'] ?? false) {
                $this->finalizeTermResults($term);
            }

            return [
                'term' => $term->name,
                'school' => $school->name,
                'total_classes' => count($classes),
                'results' => $results,
            ];
        });
    }

    /**
     * Generate report cards for students
     */
    public function generateReportCards(int $termId, array $studentIds = []): array
    {
        $term = TermSemester::with(['academicSession', 'school'])->findOrFail($termId);
        
        $query = Result::where('term_id', $termId)
            ->where('is_finalized', true)
            ->with(['student.user', 'subject', 'class']);

        if (!empty($studentIds)) {
            $query->whereIn('student_id', $studentIds);
        }

        $results = $query->get()->groupBy('student_id');
        
        $reportCards = [];
        foreach ($results as $studentId => $studentResults) {
            $student = $studentResults->first()->student;
            
            $reportCards[] = [
                'student' => [
                    'id' => $student->id,
                    'name' => $student->full_name,
                    'admission_number' => $student->admission_number,
                    'class' => $student->class->name ?? 'N/A',
                ],
                'term' => $term->name,
                'academic_session' => $term->academicSession->name,
                'subjects' => $studentResults->map(function ($result) {
                    return [
                        'subject' => $result->subject->name,
                        'marks' => "{$result->marks_obtained}/{$result->total_marks}",
                        'percentage' => $result->percentage,
                        'grade' => $result->grade,
                        'grade_point' => $result->grade_point,
                        'teacher_comment' => $result->teacher_comment,
                    ];
                }),
                'summary' => $this->calculateStudentSummary($studentResults),
                'attendance' => $this->getStudentAttendance($student->id, $termId),
                'remarks' => $this->getStudentRemarks($student->id, $termId),
            ];
        }

        return $reportCards;
    }

    /**
     * Promote students to next class
     */
    public function promoteStudents(array $promotionData): array
    {
        return DB::transaction(function () use ($promotionData) {
            $fromClassId = $promotionData['from_class_id'];
            $toClassId = $promotionData['to_class_id'] ?? null;
            $toClassLevelId = $promotionData['to_class_level_id'];
            $academicSessionId = $promotionData['academic_session_id'];
            $criteria = $promotionData['criteria'] ?? [];
            
            $results = [
                'promoted' => [],
                'retained' => [],
                'failed' => [],
            ];

            $students = Student::where('class_id', $fromClassId)
                ->with(['user', 'results' => function ($query) use ($academicSessionId) {
                    $query->where('academic_session_id', $academicSessionId)
                        ->where('is_finalized', true);
                }])
                ->get();

            foreach ($students as $student) {
                if (!$this->meetsPromotionCriteria($student, $criteria)) {
                    $results['retained'][] = [
                        'student' => $student->full_name,
                        'reason' => 'Did not meet promotion criteria',
                        'average' => $this->calculateStudentAverage($student, $academicSessionId),
                    ];
                    continue;
                }

                // Create or get target class
                $targetClass = $this->getPromotionClass(
                    $student->school_id,
                    $toClassId,
                    $toClassLevelId,
                    $academicSessionId,
                    $promotionData
                );

                // Update student class
                $oldClassId = $student->class_id;
                $student->update(['class_id' => $targetClass->id]);

                // Record promotion
                $student->promotionHistory()->create([
                    'from_class_id' => $oldClassId,
                    'to_class_id' => $targetClass->id,
                    'promoted_by' => Auth::id(),
                    'promotion_date' => now(),
                    'academic_session_id' => $academicSessionId,
                    'remarks' => $promotionData['remarks'] ?? null,
                ]);

                $results['promoted'][] = [
                    'student' => $student->full_name,
                    'from_class' => Classes::find($oldClassId)->name,
                    'to_class' => $targetClass->name,
                    'average' => $this->calculateStudentAverage($student, $academicSessionId),
                ];
            }

            // Update class student counts
            if ($fromClassId) {
                $this->updateClassStudentCount($fromClassId);
            }
            if ($targetClass ?? null) {
                $this->updateClassStudentCount($targetClass->id);
            }

            return $results;
        });
    }

    /**
     * Generate academic calendar
     */
    public function generateAcademicCalendar(int $schoolId, int $academicSessionId): array
    {
        $session = AcademicSession::with(['terms', 'school'])->findOrFail($academicSessionId);
        
        $calendar = [
            'academic_session' => $session->name,
            'school' => $session->school->name,
            'start_date' => \Carbon\Carbon::parse($session->start_date)->format('F j, Y'),
            'end_date' => \Carbon\Carbon::parse($session->end_date)->format('F j, Y'),
            'terms' => [],
            'events' => [],
            'holidays' => [],
        ];

        foreach ($session->terms as $term) {
            $calendar['terms'][] = [
                'name' => $term->name,
                'start_date' => $term->start_date->format('F j, Y'),
                'end_date' => $term->end_date->format('F j, Y'),
                'duration' => $term->start_date->diffInDays($term->end_date) . ' days',
                'status' => $term->status,
            ];

            // Add term assessments
            $assessments = Assessment::where('term_id', $term->id)
                ->where('school_id', $schoolId)
                ->get()
                ->map(function ($assessment) {
                    return [
                        'type' => 'assessment',
                        'title' => $assessment->title,
                        'date' => $assessment->assessment_date ? \Carbon\Carbon::parse($assessment->assessment_date)->format('F j, Y') : 'N/A',
                        'description' => $assessment->description,
                    ];
                });

            $calendar['events'] = array_merge($calendar['events'], $assessments->toArray());
        }

        // Add school holidays and events from notices
        $notices = \App\Models\Notice::where('school_id', $schoolId)
            ->where('type', 'holiday')
            ->where('is_published', true)
            ->whereBetween('published_at', [$session->start_date, $session->end_date])
            ->get()
            ->map(function ($notice) {
                return [
                    'type' => 'holiday',
                    'title' => $notice->title,
                    'date' => $notice->published_at->format('F j, Y'),
                    'description' => $notice->short_content,
                ];
            });

        $calendar['holidays'] = $notices->toArray();

        return $calendar;
    }

    /**
     * Calculate class rank
     */
    public function calculateClassRank(int $classId, int $termId): array
    {
        $students = Student::where('class_id', $classId)
            ->with(['user', 'results' => function ($query) use ($termId) {
                $query->where('term_id', $termId)
                    ->where('is_finalized', true);
            }])
            ->get()
            ->map(function ($student) use ($termId) {
                $average = $this->calculateStudentAverage($student, null, $termId);
                
                return [
                    'student' => $student->full_name,
                    'admission_number' => $student->admission_number,
                    'average' => $average,
                    'grade' => $this->calculateGrade($average),
                ];
            })
            ->sortByDesc('average')
            ->values()
            ->map(function ($student, $index) {
                $student['position'] = $index + 1;
                return $student;
            });

        return [
            'class' => Classes::find($classId)->name,
            'total_students' => $students->count(),
            'ranking' => $students->toArray(),
        ];
    }

    /**
     * Generate academic transcript
     */
    public function generateTranscript(int $studentId): array
    {
        $student = Student::with(['user', 'school', 'class.classLevel'])->findOrFail($studentId);
        
        $transcript = [
            'student' => [
                'name' => $student->full_name,
                'admission_number' => $student->admission_number,
                'school' => $student->school->name,
                'current_class' => $student->class->name ?? 'N/A',
            ],
            'academic_history' => [],
            'summary' => [
                'total_terms' => 0,
                'cgpa' => 0,
                'overall_grade' => '',
            ],
        ];

        // Get all academic sessions the student has been in
        $sessions = AcademicSession::where('school_id', $student->school_id)
            ->orderBy('start_date')
            ->get();

        foreach ($sessions as $session) {
            $sessionResults = Result::where('student_id', $studentId)
                ->where('academic_session_id', $session->id)
                ->where('is_finalized', true)
                ->with(['term', 'subject'])
                ->get()
                ->groupBy('term_id');

            if ($sessionResults->isEmpty()) {
                continue;
            }

            $sessionData = [
                'academic_session' => $session->name,
                'terms' => [],
                'session_average' => 0,
            ];

            $totalPoints = 0;
            $totalCredits = 0;

            foreach ($sessionResults as $termId => $termResults) {
                $term = TermSemester::find($termId);
                
                $termData = [
                    'term' => $term->name,
                    'results' => $termResults->map(function ($result) {
                        return [
                            'subject' => $result->subject->name,
                            'marks' => $result->marks_obtained,
                            'total_marks' => $result->total_marks,
                            'percentage' => $result->percentage,
                            'grade' => $result->grade,
                            'grade_point' => $result->grade_point,
                        ];
                    }),
                    'term_average' => $termResults->avg('percentage'),
                    'term_grade' => $this->calculateGrade($termResults->avg('percentage')),
                ];

                $sessionData['terms'][] = $termData;
                
                // Calculate CGPA components
                foreach ($termResults as $result) {
                    if ($result->grade_point > 0) {
                        $totalPoints += $result->grade_point;
                        $totalCredits++;
                    }
                }
            }

            $sessionData['session_average'] = $sessionResults->flatten()->avg('percentage');
            $transcript['academic_history'][] = $sessionData;
            
            // Update summary
            $transcript['summary']['total_terms'] += $sessionResults->count();
        }

        // Calculate CGPA
        if ($totalCredits > 0) {
            $transcript['summary']['cgpa'] = round($totalPoints / $totalCredits, 2);
            $transcript['summary']['overall_grade'] = $this->calculateGradeFromCGPA($transcript['summary']['cgpa']);
        }

        return $transcript;
    }

    // Helper Methods

    private function generateSessionCode(string $name): string
    {
        $year = date('Y');
        return 'AS' . $year . substr($year + 1, -2);
    }

    private function generateTermCode(string $name): string
    {
        $terms = ['First Term' => 'TERM1', 'Second Term' => 'TERM2', 'Third Term' => 'TERM3'];
        return $terms[$name] ?? 'TERM' . strtoupper(substr($name, 0, 3));
    }

    private function createDefaultTerms(AcademicSession $session): void
    {
        $terms = [
            ['name' => 'First Term', 'order' => 1, 'start_date' => $session->start_date, 'end_date' => $session->start_date->copy()->addMonths(3)],
            ['name' => 'Second Term', 'order' => 2, 'start_date' => $session->start_date->copy()->addMonths(4), 'end_date' => $session->start_date->copy()->addMonths(7)],
            ['name' => 'Third Term', 'order' => 3, 'start_date' => $session->start_date->copy()->addMonths(8), 'end_date' => $session->end_date],
        ];

        foreach ($terms as $term) {
            $session->terms()->create([
                'school_id' => $session->school_id,
                'name' => $term['name'],
                'code' => $this->generateTermCode($term['name']),
                'order' => $term['order'],
                'start_date' => $term['start_date'],
                'end_date' => $term['end_date'],
                'is_current' => $term['order'] === 1,
                'status' => $term['order'] === 1 ? 'active' : 'upcoming',
            ]);
        }
    }

    private function calculateClassResults(Classes $class, TermSemester $term, array $options): array
    {
        $students = $class->students()->with('results', function ($query) use ($term) {
            $query->where('term_id', $term->id);
        })->get();

        $classResults = [];
        foreach ($students as $student) {
            $average = $student->results->avg('percentage') ?? 0;
            
            $classResults[] = [
                'student' => $student->full_name,
                'admission_number' => $student->admission_number,
                'average' => round($average, 2),
                'grade' => $this->calculateGrade($average),
                'position' => 0, // Will be calculated after sorting
            ];
        }

        // Sort by average and assign positions
        usort($classResults, function ($a, $b) {
            return $b['average'] <=> $a['average'];
        });

        foreach ($classResults as $index => &$result) {
            $result['position'] = $index + 1;
        }

        return [
            'class_name' => $class->name,
            'total_students' => count($classResults),
            'class_average' => round(array_sum(array_column($classResults, 'average')) / count($classResults), 2),
            'results' => $classResults,
        ];
    }

    private function finalizeTermResults(TermSemester $term): void
    {
        $term->update(['status' => 'completed']);
        
        // Mark all results in this term as finalized
        Result::where('term_id', $term->id)
            ->where('is_finalized', false)
            ->update(['is_finalized' => true]);
    }

    private function calculateStudentSummary($studentResults): array
    {
        $totalSubjects = $studentResults->count();
        $passedSubjects = $studentResults->filter(fn($r) => $r->is_passed)->count();
        $average = $studentResults->avg('percentage');
        
        return [
            'total_subjects' => $totalSubjects,
            'passed_subjects' => $passedSubjects,
            'failed_subjects' => $totalSubjects - $passedSubjects,
            'average_score' => round($average, 2),
            'overall_grade' => $this->calculateGrade($average),
            'pass_percentage' => $totalSubjects > 0 ? round(($passedSubjects / $totalSubjects) * 100, 2) : 0,
        ];
    }

    private function getStudentAttendance(int $studentId, int $termId): array
    {
        $attendance = Attendance::where('student_id', $studentId)
            ->where('term_id', $termId)
            ->get();

        $total = $attendance->count();
        $present = $attendance->whereIn('status', ['present', 'late'])->count();
        
        return [
            'total_days' => $total,
            'present_days' => $present,
            'absent_days' => $attendance->where('status', 'absent')->count(),
            'attendance_rate' => $total > 0 ? round(($present / $total) * 100, 2) : 0,
        ];
    }

    private function getStudentRemarks(int $studentId, int $termId): array
    {
        return Remark::where('student_id', $studentId)
            ->where('term_id', $termId)
            ->with('teacher.user')
            ->get()
            ->map(function ($remark) {
                return [
                    'type' => ucfirst($remark->type),
                    'remark' => $remark->remark,
                    'teacher' => $remark->teacher->full_name ?? 'Unknown',
                    'date' => $remark->date->format('d/m/Y'),
                    'sentiment' => $remark->sentiment,
                ];
            })
            ->toArray();
    }

    private function meetsPromotionCriteria(Student $student, array $criteria): bool
    {
        $academicSessionId = $criteria['academic_session_id'] ?? null;
        
        if (empty($criteria)) {
            return true; // No criteria, promote all
        }

        // Check minimum average
        if (isset($criteria['minimum_average'])) {
            $average = $this->calculateStudentAverage($student, $academicSessionId);
            if ($average < $criteria['minimum_average']) {
                return false;
            }
        }

        // Check minimum attendance
        if (isset($criteria['minimum_attendance'])) {
            $attendanceRate = $this->getStudentAttendance($student->id, $criteria['term_id'] ?? null)['attendance_rate'] ?? 0;
            if ($attendanceRate < $criteria['minimum_attendance']) {
                return false;
            }
        }

        // Check maximum failed subjects
        if (isset($criteria['max_failed_subjects'])) {
            $failedSubjects = $student->results
                ->where('academic_session_id', $academicSessionId)
                ->where('is_finalized', true)
                ->filter(fn($r) => !$r->is_passed)
                ->count();
            
            if ($failedSubjects > $criteria['max_failed_subjects']) {
                return false;
            }
        }

        return true;
    }

    private function calculateStudentAverage(Student $student, ?int $academicSessionId = null, ?int $termId = null): float
    {
        $query = $student->results()->where('is_finalized', true);
        
        if ($academicSessionId) {
            $query->where('academic_session_id', $academicSessionId);
        }
        
        if ($termId) {
            $query->where('term_id', $termId);
        }
        
        return $query->avg('percentage') ?? 0;
    }

    private function getPromotionClass(int $schoolId, ?int $toClassId, int $toClassLevelId, int $academicSessionId, array $data): Classes
    {
        if ($toClassId) {
            return Classes::findOrFail($toClassId);
        }

        // Create new class for the next level
        return Classes::create([
            'school_id' => $schoolId,
            'class_level_id' => $toClassLevelId,
            'name' => $data['new_class_name'] ?? 'New Class',
            'code' => $this->generateClassCode($schoolId, $toClassLevelId),
            'academic_session_id' => $academicSessionId,
            'term_id' => $data['term_id'] ?? null,
            'status' => 'active',
        ]);
    }

    private function generateClassCode(int $schoolId, int $classLevelId): string
    {
        $school = \App\Models\School::find($schoolId);
        $classLevel = \App\Models\ClassLevel::find($classLevelId);
        
        $count = Classes::where('school_id', $schoolId)
            ->where('class_level_id', $classLevelId)
            ->count();
        
        return $school->code . '-' . $classLevel->code . '-' . ($count + 1);
    }

    private function updateClassStudentCount(int $classId): void
    {
        $class = Classes::find($classId);
        if ($class) {
            $class->update([
                'student_count' => $class->students()->count()
            ]);
        }
    }

    private function calculateGrade(float $percentage): string
    {
        if ($percentage >= 70) return 'A';
        if ($percentage >= 60) return 'B';
        if ($percentage >= 50) return 'C';
        if ($percentage >= 45) return 'D';
        if ($percentage >= 40) return 'E';
        return 'F';
    }

    private function calculateGradeFromCGPA(float $cgpa): string
    {
        if ($cgpa >= 4.5) return 'A';
        if ($cgpa >= 3.5) return 'B';
        if ($cgpa >= 2.5) return 'C';
        if ($cgpa >= 1.5) return 'D';
        if ($cgpa >= 1.0) return 'E';
        return 'F';
    }
}