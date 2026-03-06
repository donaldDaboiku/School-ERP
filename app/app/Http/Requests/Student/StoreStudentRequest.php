<?php

namespace App\Http\Requests\Student;

use Illuminate\Foundation\Http\FormRequest;

class StoreStudentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Use policies for authorization
    }

    public function rules(): array
    {
        return [
            'first_name' => 'required|string|max:100',
            'last_name' => 'required|string|max:100',
            'middle_name' => 'nullable|string|max:100',
            'email' => 'required|email|unique:users,email',
            'phone' => 'nullable|string|max:20',
            'gender' => 'required|in:male,female',
            'date_of_birth' => 'required|date|before:today',
            'address' => 'nullable|string',
            
            'admission_date' => 'nullable|date',
            'class_id' => 'nullable|exists:classes,id',
            'blood_group' => 'nullable|string|in:A+,A-,B+,B-,AB+,AB-,O+,O-',
            'genotype' => 'nullable|string|in:AA,AS,SS,AC',
            'nationality' => 'nullable|string|max:50',
            'state_of_origin' => 'nullable|string|max:50',
            'religion' => 'nullable|string|max:50',
            
            'password' => 'nullable|string|min:8',
        ];
    }

    public function messages(): array
    {
        return [
            'first_name.required' => 'Student first name is required',
            'last_name.required' => 'Student last name is required',
            'email.required' => 'Email address is required',
            'email.unique' => 'This email is already registered',
            'gender.required' => 'Gender is required',
            'date_of_birth.required' => 'Date of birth is required',
            'date_of_birth.before' => 'Date of birth must be in the past',
        ];
    }
}