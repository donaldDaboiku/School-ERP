<?php

namespace App\Helpers;

class GradingHelper
{
    /**
     * Calculate grade based on percentage
     */
    public static function calculateGrade(float $percentage, string $system = 'percentage'): array
    {
        $config = config('grading.systems.' . $system, config('grading.systems.percentage'));
        
        foreach ($config['scale'] as $grade) {
            if ($percentage >= $grade['min'] && $percentage <= ($grade['max'] ?? 100)) {
                return [
                    'grade' => $grade['grade'],
                    'point' => $grade['point'],
                    'remark' => $grade['remark'] ?? '',
                    'color' => $grade['color'] ?? '#000000',
                    'passed' => $percentage >= ($config['pass_percentage'] ?? 40),
                ];
            }
        }
        
        return [
            'grade' => 'F',
            'point' => 0.0,
            'remark' => 'Fail',
            'color' => '#dc2626',
            'passed' => false,
        ];
    }

    /**
     * Calculate GPA from array of grades
     */
    public static function calculateGPA(array $grades, string $system = 'percentage'): float
    {
        if (empty($grades)) {
            return 0.0;
        }

        $totalPoints = 0;
        $totalCredits = 0;

        foreach ($grades as $grade) {
            $gradeInfo = self::calculateGrade($grade['score'], $system);
            $weightage = $grade['weightage'] ?? 1.0;
            
            $totalPoints += $gradeInfo['point'] * $weightage;
            $totalCredits += $weightage;
        }

        return $totalCredits > 0 ? round($totalPoints / $totalCredits, 2) : 0.0;
    }

    /**
     * Get grade color for display
     */
    public static function getGradeColor(string $grade): string
    {
        $colors = [
            'A+' => '#16a34a', 'A' => '#22c55e', 'A-' => '#4ade80',
            'B+' => '#f59e0b', 'B' => '#fbbf24', 'B-' => '#fbbf24',
            'C+' => '#f97316', 'C' => '#f97316', 'C-' => '#ef4444',
            'D+' => '#ef4444', 'D' => '#ef4444', 'E' => '#dc2626',
            'F' => '#dc2626',
        ];

        return $colors[$grade] ?? '#6b7280';
    }

    /**
     * Get all grading systems
     */
    public static function getGradingSystems(): array
    {
        return config('grading.systems', []);
    }

    /**
     * Get default grading system
     */
    public static function getDefaultSystem(): string
    {
        return config('grading.default_system', 'percentage');
    }

    /**
     * Get random remark for grade
     */
    public static function getRandomRemark(string $grade): string
    {
        $remarks = config('grading.remarks.' . $grade[0], ['Good performance.']);
        return $remarks[array_rand($remarks)];
    }
}