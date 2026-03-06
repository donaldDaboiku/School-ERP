<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default Grading System
    |--------------------------------------------------------------------------
    |
    | This is the default grading system used when no specific system is
    | selected. You can define multiple grading systems and switch between
    | them based on school, class level, or academic session.
    |
    */

    'default_system' => 'percentage',

    /*
    |--------------------------------------------------------------------------
    | Available Grading Systems
    |--------------------------------------------------------------------------
    |
    | Define different grading systems for different educational levels
    | or requirements. Each system can have its own scale and calculation
    | method.
    |
    */

    'systems' => [
        'percentage' => [
            'name' => 'Percentage System',
            'description' => 'Traditional percentage-based grading (0-100%)',
            'max_score' => 100,
            'min_score' => 0,
            'pass_percentage' => 40,
            'scale' => [
                ['min' => 80, 'max' => 100, 'grade' => 'A+', 'remark' => 'Excellent', 'point' => 5.0, 'color' => '#16a34a'],
                ['min' => 75, 'max' => 79,  'grade' => 'A',  'remark' => 'Very Good',  'point' => 4.5, 'color' => '#22c55e'],
                ['min' => 70, 'max' => 74,  'grade' => 'A-', 'remark' => 'Good',      'point' => 4.0, 'color' => '#4ade80'],
                ['min' => 65, 'max' => 69,  'grade' => 'B+', 'remark' => 'Good',      'point' => 3.5, 'color' => '#f59e0b'],
                ['min' => 60, 'max' => 64,  'grade' => 'B',  'remark' => 'Above Average', 'point' => 3.0, 'color' => '#fbbf24'],
                ['min' => 55, 'max' => 59,  'grade' => 'B-', 'remark' => 'Above Average', 'point' => 2.5, 'color' => '#fbbf24'],
                ['min' => 50, 'max' => 54,  'grade' => 'C+', 'remark' => 'Average',   'point' => 2.0, 'color' => '#f97316'],
                ['min' => 45, 'max' => 49,  'grade' => 'C',  'remark' => 'Average',   'point' => 1.5, 'color' => '#f97316'],
                ['min' => 40, 'max' => 44,  'grade' => 'C-', 'remark' => 'Pass',      'point' => 1.0, 'color' => '#ef4444'],
                ['min' => 0,  'max' => 39,  'grade' => 'F',  'remark' => 'Fail',      'point' => 0.0, 'color' => '#dc2626'],
            ],
        ],

        'letter_grade' => [
            'name' => 'Letter Grade System',
            'description' => 'Standard letter grading system',
            'max_score' => 100,
            'min_score' => 0,
            'pass_grade' => 'D',
            'scale' => [
                ['min' => 90, 'max' => 100, 'grade' => 'A', 'remark' => 'Outstanding', 'point' => 4.0],
                ['min' => 80, 'max' => 89,  'grade' => 'B', 'remark' => 'Good',        'point' => 3.0],
                ['min' => 70, 'max' => 79,  'grade' => 'C', 'remark' => 'Satisfactory','point' => 2.0],
                ['min' => 60, 'max' => 69,  'grade' => 'D', 'remark' => 'Pass',        'point' => 1.0],
                ['min' => 0,  'max' => 59,  'grade' => 'F', 'remark' => 'Fail',        'point' => 0.0],
            ],
        ],

        'cgpa_4' => [
            'name' => 'CGPA 4.0 Scale',
            'description' => 'Cumulative Grade Point Average (4.0 scale)',
            'max_score' => 100,
            'min_score' => 0,
            'scale' => [
                ['min' => 85, 'max' => 100, 'grade' => 'A', 'remark' => 'Excellent', 'point' => 4.0],
                ['min' => 80, 'max' => 84,  'grade' => 'A-','remark' => 'Very Good', 'point' => 3.7],
                ['min' => 75, 'max' => 79,  'grade' => 'B+','remark' => 'Good',      'point' => 3.3],
                ['min' => 70, 'max' => 74,  'grade' => 'B', 'remark' => 'Good',      'point' => 3.0],
                ['min' => 65, 'max' => 69,  'grade' => 'B-','remark' => 'Satisfactory', 'point' => 2.7],
                ['min' => 60, 'max' => 64,  'grade' => 'C+','remark' => 'Satisfactory', 'point' => 2.3],
                ['min' => 55, 'max' => 59,  'grade' => 'C', 'remark' => 'Fair',      'point' => 2.0],
                ['min' => 50, 'max' => 54,  'grade' => 'C-','remark' => 'Fair',      'point' => 1.7],
                ['min' => 45, 'max' => 49,  'grade' => 'D+','remark' => 'Pass',      'point' => 1.3],
                ['min' => 40, 'max' => 44,  'grade' => 'D', 'remark' => 'Pass',      'point' => 1.0],
                ['min' => 0,  'max' => 39,  'grade' => 'F', 'remark' => 'Fail',      'point' => 0.0],
            ],
        ],

        'cgpa_5' => [
            'name' => 'CGPA 5.0 Scale',
            'description' => 'Cumulative Grade Point Average (5.0 scale)',
            'max_score' => 100,
            'min_score' => 0,
            'scale' => [
                ['min' => 70, 'max' => 100, 'grade' => 'A', 'remark' => 'Excellent', 'point' => 5.0],
                ['min' => 60, 'max' => 69,  'grade' => 'B', 'remark' => 'Very Good', 'point' => 4.0],
                ['min' => 50, 'max' => 59,  'grade' => 'C', 'remark' => 'Good',      'point' => 3.0],
                ['min' => 45, 'max' => 49,  'grade' => 'D', 'remark' => 'Pass',      'point' => 2.0],
                ['min' => 40, 'max' => 44,  'grade' => 'E', 'remark' => 'Pass',      'point' => 1.0],
                ['min' => 0,  'max' => 39,  'grade' => 'F', 'remark' => 'Fail',      'point' => 0.0],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Grade Calculation Settings
    |--------------------------------------------------------------------------
    |
    | Configuration for how grades are calculated and weighted.
    |
    */

    'calculation' => [
        'round_decimals' => 2,
        'enable_curving' => false,
        'curving_percentage' => 5,
        'include_incomplete' => false,
        'minimum_subjects_for_pass' => 5,
    ],

    /*
    |--------------------------------------------------------------------------
    | Result Publication Settings
    |--------------------------------------------------------------------------
    |
    | Settings for when and how results are published.
    |
    */

    'publication' => [
        'auto_calculate_gpa' => true,
        'auto_generate_remarks' => true,
        'show_position' => true,
        'show_percentile' => true,
        'allow_result_correction' => true,
        'correction_deadline_days' => 7,
        'publish_to_parent_portal' => true,
        'send_result_sms' => false,
        'send_result_email' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Transcript Settings
    |--------------------------------------------------------------------------
    |
    | Configuration for generating academic transcripts.
    |
    */

    'transcript' => [
        'header_logo' => null,
        'header_title' => 'ACADEMIC TRANSCRIPT',
        'header_subtitle' => 'STATEMENT OF RESULTS',
        'issuing_authority' => 'SCHOOL BOARD',
        'signature_required' => true,
        'signatory_title' => 'PRINCIPAL',
        'watermark' => true,
        'watermark_text' => 'OFFICIAL TRANSCRIPT',
        'include_cgpa' => true,
        'include_class_position' => true,
        'include_percentile' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Grade Remarks
    |--------------------------------------------------------------------------
    |
    | Pre-defined remarks for different grade ranges.
    |
    */

    'remarks' => [
        'A' => [
            'Excellent work! Maintain this standard.',
            'Outstanding performance. Keep it up!',
            'Exceptional understanding of the subject.',
        ],
        'B' => [
            'Very good work. Aim for excellence.',
            'Good performance with room for improvement.',
            'Solid understanding of concepts.',
        ],
        'C' => [
            'Satisfactory work. Needs more effort.',
            'Average performance. Focus on weak areas.',
            'Fair understanding. Practice more.',
        ],
        'D' => [
            'Barely passed. Requires significant improvement.',
            'Minimum passing grade. Needs extra help.',
            'Basic understanding. Consider remedial classes.',
        ],
        'F' => [
            'Failed. Requires remedial work and retake.',
            'Insufficient understanding of subject matter.',
            'Needs to repeat the subject.',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Grade Point Average (GPA) Calculation
    |--------------------------------------------------------------------------
    |
    | Settings for GPA calculation.
    |
    */

    'gpa_calculation' => [
        'method' => 'weighted', // 'weighted' or 'unweighted'
        'include_electives' => true,
        'minimum_credits_for_gpa' => 1.0,
        'round_gpa_to' => 2, // decimal places
        'honors_threshold' => [
            'summa_cum_laude' => 3.9,
            'magna_cum_laude' => 3.7,
            'cum_laude' => 3.5,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Subject Weightage
    |--------------------------------------------------------------------------
    |
    | Different subjects can have different weightage in GPA calculation.
    |
    */

    'subject_weightage' => [
        'core' => 1.0,
        'elective' => 1.0,
        'practical' => 0.5,
        'project' => 1.5,
        'extra_curricular' => 0.5,
    ],

    /*
    |--------------------------------------------------------------------------
    | Result Templates
    |--------------------------------------------------------------------------
    |
    | Pre-defined templates for result sheets and reports.
    |
    */

    'templates' => [
        'report_card' => 'templates.results.report-card',
        'transcript' => 'templates.results.transcript',
        'term_result' => 'templates.results.term-result',
        'bulk_result' => 'templates.results.bulk-result',
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache Settings
    |--------------------------------------------------------------------------
    |
    | Cache duration for grade calculations.
    |
    */

    'cache' => [
        'duration' => 3600, // seconds (1 hour)
        'enable' => true,
    ],
];