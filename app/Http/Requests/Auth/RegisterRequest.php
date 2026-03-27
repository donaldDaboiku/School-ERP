<?php

namespace App\Http\Requests\Auth;

use App\Rules\NigerianPhone;
use Illuminate\Foundation\Http\FormRequest;

class RegisterRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => 'required_without_all:first_name,last_name|string|max:150',
            'first_name' => 'required_without:name|string|max:100',
            'last_name' => 'required_without:name|string|max:100',
            'middle_name' => 'nullable|string|max:100',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8|confirmed',
            'phone' => ['nullable', 'string', 'max:20', new NigerianPhone()],
            'gender' => 'nullable|in:male,female,other',
            'date_of_birth' => 'nullable|date|before:today',
            'address' => 'nullable|string|max:255',
            'school_id' => 'nullable|exists:schools,id',
            'user_type' => 'nullable|in:super_admin,admin,principal,teacher,student,parent,accountant,librarian,staff',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required_without_all' => 'Name is required if first and last name are not provided.',
            'first_name.required_without' => 'First name is required if full name is not provided.',
            'last_name.required_without' => 'Last name is required if full name is not provided.',
            'email.required' => 'Email is required.',
            'email.unique' => 'This email is already registered.',
            'password.confirmed' => 'Password confirmation does not match.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if (!$this->filled('name') && ($this->filled('first_name') || $this->filled('last_name'))) {
            $name = trim(($this->input('first_name') ?? '') . ' ' . ($this->input('last_name') ?? ''));
            if ($name !== '') {
                $this->merge(['name' => $name]);
            }
        }
    }
}
