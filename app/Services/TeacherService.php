<?php
namespace App\Services;

use App\Models\Teacher;
use App\Models\Subject;
use App\Models\ClassModel;
use App\Models\Timetable;
use App\Models\Grade;
use App\Events\TeacherAssigned;
use App\Events\ClassScheduled;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;

class TeacherService {
    
    public function createTeacher(array $data) {
        DB::beginTransaction();
        
        try {
            // Generate teacher ID
            $teacherId = $this->generateTeacherId();
            
            $teacher = Teacher::create([
                'teacher_id' => $teacherId,
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'email' => $data['email'],
                'phone' => $data['phone'],
                'date_of_birth' => $data['date_of_birth'],
                'gender' => $data['gender'],
                'address' => $data['address'],
                'qualification' => $data['qualification'],
                'specialization' => $data['specialization'],
                'date_of_joining' => $data['date_of_joining'],
                'employment_type' => $data['employment_type'] ?? 'full-time',
                'status' => 'active'
            ]);
            
            // Assign subjects if provided
            if (isset($data['subjects']) && is_array($data['subjects'])) {
                $teacher->subjects()->sync($data['subjects']);
            }
            
            DB::commit();
            return ['success' => true, 'teacher' => $teacher, 'message' => 'Teacher created successfully'];
            
        } catch (\Exception $e) {
            DB::rollBack();
            return ['success' => false, 'message' => 'Failed to create teacher: ' . $e->getMessage()];
        }
    }
    
    public function assignSubjects($teacherId, array $subjectIds, $academicYear = null) {
        $teacher = Teacher::find($teacherId);
        
        if (!$teacher) {
            return ['success' => false, 'message' => 'Teacher not found'];
        }
        
        try {
            $teacher->subjects()->sync($subjectIds);
            
            // Record assignment history
            foreach ($subjectIds as $subjectId) {
                DB::table('teacher_subject_assignments')->updateOrInsert(
                    [
                        'teacher_id' => $teacherId,
                        'subject_id' => $subjectId,
                        'academic_year' => $academicYear ?? date('Y')
                    ],
                    [
                        'assigned_at' => now(),
                        'assigned_by' => Auth::id() ?? null
                    ]
                );
            }

            event(new TeacherAssigned($teacher, null, $subjectIds, 'subject-teacher', $academicYear ? (int) $academicYear : null));
            
            return ['success' => true, 'message' => 'Subjects assigned successfully'];
            
        } catch (\Exception $e) {
            return ['success' => false, 'message' => 'Failed to assign subjects: ' . $e->getMessage()];
        }
    }
    
    public function assignClasses($teacherId, array $classIds, $role = 'subject-teacher', $subjectId = null) {
        $teacher = Teacher::find($teacherId);
        
        if (!$teacher) {
            return ['success' => false, 'message' => 'Teacher not found'];
        }
        
        DB::beginTransaction();
        
        try {
            foreach ($classIds as $classId) {
                if ($role === 'homeroom') {
                    // Assign as homeroom teacher
                    $class = ClassModel::find($classId);
                    if ($class) {
                        $class->homeroom_teacher_id = $teacherId;
                        $class->save();
                    }
                } else if ($role === 'subject-teacher' && $subjectId) {
                    // Assign as subject teacher for specific class
                    DB::table('class_subject_teachers')->updateOrInsert(
                        [
                            'class_id' => $classId,
                            'subject_id' => $subjectId
                        ],
                        [
                            'teacher_id' => $teacherId,
                            'assigned_at' => now()
                        ]
                    );
                }
            }

            event(new TeacherAssigned($teacher, $classIds, $subjectId ? [$subjectId] : null, $role, $academicYear ?? null));
            
            DB::commit();
            return ['success' => true, 'message' => 'Classes assigned successfully'];
            
        } catch (\Exception $e) {
            DB::rollBack();
            return ['success' => false, 'message' => 'Failed to assign classes: ' . $e->getMessage()];
        }
    }
    
    public function getTeacherWorkload($teacherId, $academicYear = null) {
        $teacher = Teacher::with([
            'subjects',
            'classes.students',
            'timetable.slot',
            'timetable.class'
        ])->find($teacherId);
        
        if (!$teacher) {
            return null;
        }
        
        $academicYear = $academicYear ?? date('Y');
        
        // Get timetable entries for the teacher
        $timetable = $teacher->timetable->where('academic_year', $academicYear);
        
        $workload = [
            'teacher_info' => $teacher,
            'workload_summary' => [
                'total_subjects' => $teacher->subjects->count(),
                'total_classes' => $teacher->classes->count(),
                'total_students' => $teacher->classes->sum(function($class) {
                    return $class->students->count();
                }),
                'weekly_periods' => $timetable->count(),
                'weekly_hours' => $timetable->sum(function($entry) {
                    return $entry->slot ? 
                        (strtotime($entry->slot->end_time) - strtotime($entry->slot->start_time)) / 3600 : 0;
                })
            ],
            'daily_schedule' => $this->formatDailySchedule($timetable),
            'subject_distribution' => $this->getSubjectDistribution($teacher, $academicYear),
            'grading_responsibilities' => $this->getGradingResponsibilities($teacherId, $academicYear)
        ];
        
        return $workload;
    }
    
    public function createTimetableEntry($teacherId, array $data) {
        try {
            $timetable = Timetable::create([
                'teacher_id' => $teacherId,
                'class_id' => $data['class_id'],
                'subject_id' => $data['subject_id'],
                'slot_id' => $data['slot_id'],
                'day_of_week' => $data['day_of_week'],
                'academic_year' => $data['academic_year'] ?? date('Y'),
                'room_number' => $data['room_number'] ?? null,
                'effective_from' => $data['effective_from'] ?? now(),
                'effective_to' => $data['effective_to'] ?? null
            ]);

            event(new ClassScheduled($timetable, [
                'school_id' => $timetable->school_id ?? null,
                'academic_session_id' => $data['academic_session_id'] ?? null,
                'teacher_id' => $teacherId,
                'class_id' => $data['class_id'],
                'subject_id' => $data['subject_id'],
                'day_of_week' => $data['day_of_week'],
                'slot_id' => $data['slot_id'],
            ]));
            
            return ['success' => true, 'timetable' => $timetable, 'message' => 'Timetable entry created'];
            
        } catch (\Exception $e) {
            return ['success' => false, 'message' => 'Failed to create timetable: ' . $e->getMessage()];
        }
    }
    
    public function getTeacherPerformance($teacherId, $academicYear) {
        $teacher = Teacher::with(['subjects', 'classes.students.grades'])->find($teacherId);
        
        if (!$teacher) {
            return null;
        }
        
        $performance = [
            'teacher_info' => $teacher,
            'subject_performance' => [],
            'student_feedback' => $this->getStudentFeedback($teacherId),
            'attendance_rate' => $this->getTeacherAttendanceRate($teacherId, $academicYear)
        ];
        
        // Calculate performance for each subject
        foreach ($teacher->subjects as $subject) {
            $subjectGrades = [];
            $studentCount = 0;
            
            foreach ($teacher->classes as $class) {
                foreach ($class->students as $student) {
                    $grade = $student->grades
                        ->where('subject_id', $subject->id)
                        ->where('academic_year', $academicYear)
                        ->first();
                    
                    if ($grade) {
                        $subjectGrades[] = $grade->score;
                        $studentCount++;
                    }
                }
            }
            
            if ($studentCount > 0) {
                $performance['subject_performance'][$subject->name] = [
                    'average_score' => array_sum($subjectGrades) / count($subjectGrades),
                    'student_count' => $studentCount,
                    'pass_rate' => (count(array_filter($subjectGrades, function($score) {
                        return $score >= 60;
                    })) / count($subjectGrades)) * 100
                ];
            }
        }
        
        return $performance;
    }
    
    public function recordTeacherAttendance($teacherId, $date, $status, $remarks = null) {
        try {
            DB::table('teacher_attendance')->updateOrCreate(
                [
                    'teacher_id' => $teacherId,
                    'date' => $date
                ],
                [
                    'status' => $status,
                    'remarks' => $remarks,
                    'recorded_by' => Auth::id() ?? null
                ]
            );
            
            return ['success' => true, 'message' => 'Teacher attendance recorded'];
            
        } catch (\Exception $e) {
            return ['success' => false, 'message' => 'Failed to record attendance: ' . $e->getMessage()];
        }
    }
    
    private function generateTeacherId() {
        $year = date('y');
        $sequence = Teacher::whereYear('created_at', date('Y'))->count() + 1;
        return 'TCH' . $year . str_pad($sequence, 4, '0', STR_PAD_LEFT);
    }
    
    private function formatDailySchedule($timetable) {
        $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
        $schedule = [];
        
        foreach ($days as $day) {
            $dayEntries = $timetable->where('day_of_week', $day)->sortBy('slot.start_time');
            
            if ($dayEntries->isNotEmpty()) {
                $schedule[$day] = $dayEntries->map(function($entry) {
                    return [
                        'time' => $entry->slot->start_time . ' - ' . $entry->slot->end_time,
                        'class' => $entry->class->name ?? 'N/A',
                        'subject' => $entry->subject->name ?? 'N/A',
                        'room' => $entry->room_number
                    ];
                })->values();
            }
        }
        
        return $schedule;
    }
    
    private function getSubjectDistribution($teacher, $academicYear) {
        $distribution = [];
        
        foreach ($teacher->subjects as $subject) {
            $classCount = DB::table('class_subject_teachers')
                ->where('teacher_id', $teacher->id)
                ->where('subject_id', $subject->id)
                ->count();
            
            $studentCount = 0;
            $classes = DB::table('class_subject_teachers')
                ->where('teacher_id', $teacher->id)
                ->where('subject_id', $subject->id)
                ->pluck('class_id');
            
            foreach ($classes as $classId) {
                $studentCount += DB::table('students')
                    ->where('class_id', $classId)
                    ->where('status', 'active')
                    ->count();
            }
            
            $distribution[$subject->name] = [
                'classes' => $classCount,
                'students' => $studentCount
            ];
        }
        
        return $distribution;
    }
    
    private function getGradingResponsibilities($teacherId, $academicYear) {
        $subjects = DB::table('class_subject_teachers')
            ->where('teacher_id', $teacherId)
            ->pluck('subject_id')
            ->unique();
        
        $responsibilities = [];
        
        foreach ($subjects as $subjectId) {
            $subject = Subject::find($subjectId);
            $pendingGrades = Grade::where('subject_id', $subjectId)
                ->where('academic_year', $academicYear)
                ->whereNull('score')
                ->count();
            
            $graded = Grade::where('subject_id', $subjectId)
                ->where('academic_year', $academicYear)
                ->whereNotNull('score')
                ->count();
            
            $totalStudents = Grade::where('subject_id', $subjectId)
                ->where('academic_year', $academicYear)
                ->count();
            
            $responsibilities[$subject->name] = [
                'total_students' => $totalStudents,
                'graded' => $graded,
                'pending' => $pendingGrades,
                'completion_rate' => $totalStudents > 0 ? round(($graded / $totalStudents) * 100, 2) : 0
            ];
        }
        
        return $responsibilities;
    }
    
    private function getStudentFeedback($teacherId) {
        // This would typically come from a feedback system
        // For now, returning mock data
        return [
            'average_rating' => 4.2,
            'total_feedback' => 45,
            'positive_comments' => ['Excellent teacher', 'Very helpful', 'Great explanations'],
            'areas_for_improvement' => ['Could be more organized', 'Sometimes rushes through material']
        ];
    }
    
    private function getTeacherAttendanceRate($teacherId, $academicYear) {
        $startDate = $academicYear . '-01-01';
        $endDate = $academicYear . '-12-31';
        
        $totalDays = DB::table('teacher_attendance')
            ->where('teacher_id', $teacherId)
            ->whereBetween('date', [$startDate, $endDate])
            ->count();
        
        $presentDays = DB::table('teacher_attendance')
            ->where('teacher_id', $teacherId)
            ->where('status', 'present')
            ->whereBetween('date', [$startDate, $endDate])
            ->count();
        
        return $totalDays > 0 ? round(($presentDays / $totalDays) * 100, 2) : 100;
    }
}
