<?php
namespace App\Services;

use App\Models\Student;
use App\Models\Teacher;
use App\Models\FinancialTransaction;
use App\Models\Attendance;
use App\Models\Grade;

class ReportService {
    
    public function generateFinancialReport($startDate, $endDate, $type = 'summary') {
        // Get financial data for the period
        $transactions = FinancialTransaction::whereBetween('date', [$startDate, $endDate])->get();
        
        $report = [
            'period' => $startDate . ' to ' . $endDate,
            'total_income' => $transactions->where('type', 'income')->sum('amount'),
            'total_expenses' => $transactions->where('type', 'expense')->sum('amount'),
            'net_balance' => $transactions->where('type', 'income')->sum('amount') - 
                             $transactions->where('type', 'expense')->sum('amount'),
            'transactions' => $type === 'detailed' ? $transactions : []
        ];
        
        return $report;
    }
    
    public function generateAcademicReport($academicYear, $term, $classId = null) {
        $query = Grade::where('academic_year', $academicYear)
                     ->where('term', $term);
        
        if ($classId) {
            $query->whereHas('student', function($q) use ($classId) {
                $q->where('class_id', $classId);
            });
        }
        
        $grades = $query->with('student', 'subject')->get();
        
        $report = [
            'academic_year' => $academicYear,
            'term' => $term,
            'total_students' => $grades->groupBy('student_id')->count(),
            'average_scores' => $this->calculateClassAverages($grades),
            'top_performers' => $this->getTopPerformers($grades),
            'detailed_grades' => $grades
        ];
        
        return $report;
    }
    
    public function generateAttendanceReport($entityType, $entityId, $startDate, $endDate) {
        $attendance = Attendance::where('entity_type', $entityType)
                               ->where('entity_id', $entityId)
                               ->whereBetween('date', [$startDate, $endDate])
                               ->get();
        
        $totalDays = $attendance->count();
        $presentDays = $attendance->where('status', 'present')->count();
        $absentDays = $attendance->where('status', 'absent')->count();
        $lateDays = $attendance->where('status', 'late')->count();
        
        return [
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'period' => $startDate . ' to ' . $endDate,
            'total_days' => $totalDays,
            'present' => $presentDays,
            'absent' => $absentDays,
            'late' => $lateDays,
            'attendance_rate' => $totalDays > 0 ? round(($presentDays / $totalDays) * 100, 2) : 0,
            'daily_records' => $attendance
        ];
    }
    
    public function generateStudentPerformanceReport($studentId, $academicYear = null) {
        $student = Student::with('grades.subject', 'attendance', 'class')->find($studentId);
        
        if (!$student) {
            return ['error' => 'Student not found'];
        }
        
        $query = $student->grades();
        if ($academicYear) {
            $query->where('academic_year', $academicYear);
        }
        
        $grades = $query->with('subject')->get();
        $attendance = $student->attendance()->whereBetween('date', 
            [now()->startOfYear(), now()->endOfYear()])->get();
        
        return [
            'student_info' => [
                'id' => $student->id,
                'name' => $student->name,
                'class' => $student->class->name ?? 'N/A',
                'roll_number' => $student->roll_number
            ],
            'academic_performance' => [
                'overall_average' => $grades->avg('score'),
                'subject_wise_scores' => $grades->groupBy('subject.name')->map(function($item) {
                    return $item->avg('score');
                }),
                'term_wise_performance' => $grades->groupBy('term')->map(function($item) {
                    return $item->avg('score');
                })
            ],
            'attendance_summary' => [
                'present' => $attendance->where('status', 'present')->count(),
                'absent' => $attendance->where('status', 'absent')->count(),
                'late' => $attendance->where('status', 'late')->count(),
                'attendance_rate' => $attendance->count() > 0 ? 
                    round(($attendance->where('status', 'present')->count() / $attendance->count()) * 100, 2) : 0
            ],
            'recommendations' => $this->generateRecommendations($grades, $attendance)
        ];
    }
    
    public function generateTeacherPerformanceReport($teacherId, $academicYear) {
        $teacher = Teacher::with(['subjects', 'classes.students.grades'])->find($teacherId);
        
        $report = [
            'teacher_info' => $teacher,
            'subjects_taught' => $teacher->subjects,
            'student_performance' => $this->calculateTeacherStudentPerformance($teacher),
            'workload_analysis' => $this->analyzeTeacherWorkload($teacher)
        ];
        
        return $report;
    }
    
    private function calculateClassAverages($grades) {
        return $grades->groupBy('subject_id')->map(function($subjectGrades) {
            return [
                'subject_name' => $subjectGrades->first()->subject->name,
                'average_score' => $subjectGrades->avg('score'),
                'highest_score' => $subjectGrades->max('score'),
                'lowest_score' => $subjectGrades->min('score')
            ];
        })->values();
    }
    
    private function getTopPerformers($grades, $limit = 5) {
        return $grades->groupBy('student_id')->map(function($studentGrades) {
            return [
                'student' => $studentGrades->first()->student,
                'average_score' => $studentGrades->avg('score')
            ];
        })->sortByDesc('average_score')->take($limit)->values();
    }
    
    private function generateRecommendations($grades, $attendance) {
        $recommendations = [];
        
        // Academic recommendations
        $lowSubjects = $grades->where('score', '<', 60)->groupBy('subject.name');
        if ($lowSubjects->count() > 0) {
            $recommendations[] = "Needs improvement in: " . $lowSubjects->keys()->implode(', ');
        }
        
        // Attendance recommendations
        $attendanceRate = ($attendance->where('status', 'present')->count() / max($attendance->count(), 1)) * 100;
        if ($attendanceRate < 85) {
            $recommendations[] = "Low attendance rate (" . round($attendanceRate, 2) . "%). Needs improvement.";
        }
        
        return empty($recommendations) ? ['Good performance. Keep it up!'] : $recommendations;
    }
    
    private function calculateTeacherStudentPerformance($teacher) {
        $performance = [];
        foreach ($teacher->subjects as $subject) {
            $subjectGrades = [];
            foreach ($teacher->classes as $class) {
                foreach ($class->students as $student) {
                    $grade = $student->grades->where('subject_id', $subject->id)->first();
                    if ($grade) {
                        $subjectGrades[] = $grade->score;
                    }
                }
            }
            
            if (!empty($subjectGrades)) {
                $performance[$subject->name] = [
                    'average' => array_sum($subjectGrades) / count($subjectGrades),
                    'total_students' => count($subjectGrades)
                ];
            }
        }
        return $performance;
    }
    
    private function analyzeTeacherWorkload($teacher) {
        return [
            'total_subjects' => $teacher->subjects->count(),
            'total_classes' => $teacher->classes->count(),
            'total_students' => $teacher->classes->sum(function($class) {
                return $class->students->count();
            }),
            'weekly_periods' => $teacher->subjects->count() * 5 // Assuming 5 periods per subject per week
        ];
    }
}