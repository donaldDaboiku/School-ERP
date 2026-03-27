<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ValidAdmissionNumber implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $admission = strtoupper(trim((string) $value));

        $patterns = [
            '/^STU\d{4}\d{4}$/',          // STU20260001
            '/^[A-Z]{2,5}\d{4}\/\d{2}$/', // SCH0001/26
            '/^[A-Z]{3}\d{4}\/[A-Z]{2}\d{2}$/', // STU2024/AB01
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $admission)) {
                return;
            }
        }

        $fail('The ' . $attribute . ' must be a valid admission number (e.g., STU20260001 or SCH0001/26).');
    }
}
