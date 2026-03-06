<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Student\StoreStudentRequest;
use App\Http\Requests\Student\UpdateStudentRequest;
use App\Http\Resources\StudentResource;
use App\Models\Student;
use App\Services\StudentService;
use Illuminate\Http\Request;

class StudentController extends Controller
{
    public function __construct(
        private StudentService $studentService
    ) {}

    /**
     * Display a listing of students
     */
    public function index(Request $request)
    {
        $students = Student::query()
            ->where('school_id', config('app.current_school_id'))
            ->with(['user', 'class'])
            ->when($request->search, function ($query, $search) {
                $query->whereHas('user', function ($q) use ($search) {
                    $q->where('first_name', 'like', "%{$search}%")
                      ->orWhere('last_name', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%");
                })->orWhere('admission_number', 'like', "%{$search}%");
            })
            ->when($request->class_id, fn($q, $classId) => $q->where('class_id', $classId))
            ->when($request->status, fn($q, $status) => $q->where('status', $status))
            ->latest()
            ->paginate($request->per_page ?? 20);

        return StudentResource::collection($students);
    }

    /**
     * Store a newly created student
     */
    public function store(StoreStudentRequest $request)
    {
        $student = $this->studentService->create($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Student created successfully',
            'data' => new StudentResource($student),
        ], 201);
    }

    /**
     * Display the specified student
     */
    public function show(Student $student)
    {
        $student->load(['user', 'class', 'parents']);

        return response()->json([
            'success' => true,
            'data' => new StudentResource($student),
        ]);
    }

    /**
     * Update the specified student
     */
    public function update(UpdateStudentRequest $request, Student $student)
    {
        $student = $this->studentService->update($student, $request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Student updated successfully',
            'data' => new StudentResource($student),
        ]);
    }

    /**
     * Remove the specified student
     */
    public function destroy(Student $student)
    {
        $this->studentService->delete($student);

        return response()->json([
            'success' => true,
            'message' => 'Student deleted successfully',
        ]);
    }
}