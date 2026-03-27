<?php

namespace App\Enums;

enum UserType: string
{
    case SuperAdmin = 'super_admin';
    case Admin = 'admin';
    case Principal = 'principal';
    case Teacher = 'teacher';
    case Student = 'student';
    case Parent = 'parent';
    case Accountant = 'accountant';
    case Librarian = 'librarian';
    case Staff = 'staff';

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Super Admin',
            self::Admin => 'Admin',
            self::Principal => 'Principal',
            self::Teacher => 'Teacher',
            self::Student => 'Student',
            self::Parent => 'Parent',
            self::Accountant => 'Accountant',
            self::Librarian => 'Librarian',
            self::Staff => 'Staff',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        $labels = [];
        foreach (self::cases() as $case) {
            $labels[$case->value] = $case->label();
        }

        return $labels;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $case) => $case->value,
            self::cases()
        );
    }

    public static function fromValue(?string $value): ?self
    {
        return $value === null ? null : self::tryFrom($value);
    }
}
