<?php

namespace App\Http\Requests\Student;

use Illuminate\Foundation\Http\FormRequest;

class UpdateStudentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $studentId = $this->route('student');
        
        return [
            'first_name' => 'sometimes|string|max:100',
            'last_name' => 'sometimes|string|max:100',
            'middle_name' => 'nullable|string|max:100',
            'email' => 'sometimes|email|unique:users,email,' . $studentId . ',id',
            'phone' => 'nullable|string|max:20',
            'gender' => 'sometimes|in:male,female',
            'date_of_birth' => 'sometimes|date|before:today',
            'address' => 'nullable|string',
            
            'class_id' => 'nullable|exists:classes,id',
            'blood_group' => 'nullable|string|in:A+,A-,B+,B-,AB+,AB-,O+,O-',
            'genotype' => 'nullable|string|in:AA,AS,SS,AC',
            'state_of_origin' => 'nullable|string|max:50',
            'religion' => 'nullable|string|max:50',
        ];
    }
}