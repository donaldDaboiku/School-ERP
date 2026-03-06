<?php

namespace App\Services;

use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class StudentService
{
    /**
     * Create a new student with user account
     */
    public function create(array $data): Student
    {
        return DB::transaction(function () use ($data) {
            // Create user account
            $user = User::create([
                'school_id' => config('app.current_school_id'),
                'email' => $data['email'],
                'password' => Hash::make($data['password'] ?? Str::random(12)),
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'middle_name' => $data['middle_name'] ?? null,
                'phone' => $data['phone'] ?? null,
                'gender' => $data['gender'] ?? null,
                'date_of_birth' => $data['date_of_birth'] ?? null,
                'address' => $data['address'] ?? null,
            ]);

            // Assign student role
            $user->roles()->attach(
                \App\Models\Role::where('name', 'student')->first()->id
            );

            // Create student record
            $student = Student::create([
                'user_id' => $user->id,
                'school_id' => config('app.current_school_id'),
                'admission_number' => $this->generateAdmissionNumber(),
                'admission_date' => $data['admission_date'] ?? now(),
                'class_id' => $data['class_id'] ?? null,
                'blood_group' => $data['blood_group'] ?? null,
                'genotype' => $data['genotype'] ?? null,
                'nationality' => $data['nationality'] ?? 'Nigerian',
                'state_of_origin' => $data['state_of_origin'] ?? null,
                'religion' => $data['religion'] ?? null,
                'status' => 'active',
            ]);

            return $student->load('user', 'class');
        });
    }

    /**
     * Update student information
     */
    public function update(Student $student, array $data): Student
    {
        return DB::transaction(function () use ($student, $data) {
            // Update user information
            $student->user->update([
                'first_name' => $data['first_name'] ?? $student->user->first_name,
                'last_name' => $data['last_name'] ?? $student->user->last_name,
                'middle_name' => $data['middle_name'] ?? $student->user->middle_name,
                'phone' => $data['phone'] ?? $student->user->phone,
                'address' => $data['address'] ?? $student->user->address,
                'gender' => $data['gender'] ?? $student->user->gender,
                'date_of_birth' => $data['date_of_birth'] ?? $student->user->date_of_birth,
            ]);

            // Update student information
            $student->update([
                'class_id' => $data['class_id'] ?? $student->class_id,
                'blood_group' => $data['blood_group'] ?? $student->blood_group,
                'genotype' => $data['genotype'] ?? $student->genotype,
                'state_of_origin' => $data['state_of_origin'] ?? $student->state_of_origin,
                'religion' => $data['religion'] ?? $student->religion,
            ]);

            return $student->fresh(['user', 'class']);
        });
    }

    /**
     * Delete student (soft delete)
     */
    public function delete(Student $student): bool
    {
        return $student->delete();
    }

    /**
     * Generate unique admission number
     */
    private function generateAdmissionNumber(): string
    {
        $year = date('Y');
        $schoolId = config('app.current_school_id');
        
        $lastStudent = Student::where('school_id', $schoolId)
            ->whereYear('created_at', $year)
            ->latest('id')
            ->first();

        $sequence = $lastStudent 
            ? (int) substr($lastStudent->admission_number, -4) + 1 
            : 1;

        return sprintf('STU%s%04d', $year, $sequence);
    }

    /**
     * Assign student to class
     */
    public function assignToClass(Student $student, int $classId): Student
    {
        $student->update(['class_id' => $classId]);
        return $student->fresh('class');
    }

    /**
     * Get students by class
     */
    public function getByClass(int $classId)
    {
        return Student::where('class_id', $classId)
            ->where('school_id', config('app.current_school_id'))
            ->where('status', 'active')
            ->with(['user', 'class'])
            ->get();
    }
}