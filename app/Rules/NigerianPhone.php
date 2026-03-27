<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class NigerianPhone implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $phone = preg_replace('/\D+/', '', (string) $value);

        if (!$phone) {
            $fail('The ' . $attribute . ' must be a valid Nigerian phone number.');
            return;
        }

        if (strlen($phone) === 11 && str_starts_with($phone, '0')) {
            $phone = '234' . substr($phone, 1);
        } elseif (strlen($phone) === 10) {
            $phone = '234' . $phone;
        }

        if (!preg_match('/^234(7|8|9)(0|1)\d{8}$/', $phone)) {
            $fail('The ' . $attribute . ' must be a valid Nigerian phone number.');
        }
    }
}
