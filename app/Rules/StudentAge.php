<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Carbon;

class StudentAge implements ValidationRule
{
    private ?int $minAge;
    private ?int $maxAge;

    public function __construct(?int $minAge = null, ?int $maxAge = null)
    {
        $this->minAge = $minAge;
        $this->maxAge = $maxAge;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $age = null;

        if (is_numeric($value)) {
            $age = (int) $value;
        } else {
            try {
                $date = Carbon::parse($value);
            } catch (\Exception $e) {
                $fail('The ' . $attribute . ' must be a valid date or age.');
                return;
            }

            if ($date->isFuture()) {
                $fail('The ' . $attribute . ' must be a date in the past.');
                return;
            }

            $age = $date->age;
        }

        if ($age < 0) {
            $fail('The ' . $attribute . ' must be a valid age.');
            return;
        }

        if ($this->minAge !== null && $age < $this->minAge) {
            $fail('The ' . $attribute . ' must be at least ' . $this->minAge . ' years old.');
            return;
        }

        if ($this->maxAge !== null && $age > $this->maxAge) {
            $fail('The ' . $attribute . ' must be at most ' . $this->maxAge . ' years old.');
            return;
        }
    }
}
