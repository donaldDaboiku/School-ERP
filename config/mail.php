<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Mailer
    |--------------------------------------------------------------------------
    |
    | This option controls the default mailer that is used to send any email
    | messages sent by your application. Alternative mailers may be setup
    | and used as needed; however, this mailer will be used by default.
    |
    */

    'default' => env('MAIL_MAILER', 'smtp'),

    /*
    |--------------------------------------------------------------------------
    | Mailer Configurations
    |--------------------------------------------------------------------------
    |
    | Here you may configure all of the mailers used by your application plus
    | their respective settings. Several examples have been configured for
    | you and you are free to add your own as your application requires.
    |
    | Laravel supports a variety of mail "transport" drivers to be used while
    | sending an e-mail. You will specify which one you are using for your
    | mailers below. You are free to add additional mailers as required.
    |
    | Supported: "smtp", "sendmail", "mailgun", "ses", "ses-v2",
    |            "postmark", "log", "array", "failover", "roundrobin"
    |
    */

    'mailers' => [
        'smtp' => [
            'transport' => 'smtp',
            'url' => env('MAIL_URL'),
            'host' => env('MAIL_HOST', 'smtp.mailgun.org'),
            'port' => env('MAIL_PORT', 587),
            'encryption' => env('MAIL_ENCRYPTION', 'tls'),
            'username' => env('MAIL_USERNAME'),
            'password' => env('MAIL_PASSWORD'),
            'timeout' => null,
            'local_domain' => env('MAIL_EHLO_DOMAIN'),
        ],

        'ses' => [
            'transport' => 'ses',
        ],

        'postmark' => [
            'transport' => 'postmark',
            // 'message_stream_id' => null,
            // 'client' => [
            //     'timeout' => 5,
            // ],
        ],

        'mailgun' => [
            'transport' => 'mailgun',
            // 'client' => [
            //     'timeout' => 5,
            // ],
        ],

        'sendmail' => [
            'transport' => 'sendmail',
            'path' => env('MAIL_SENDMAIL_PATH', '/usr/sbin/sendmail -bs -i'),
        ],

        'log' => [
            'transport' => 'log',
            'channel' => env('MAIL_LOG_CHANNEL'),
        ],

        'array' => [
            'transport' => 'array',
        ],

        'failover' => [
            'transport' => 'failover',
            'mailers' => [
                'smtp',
                'log',
            ],
        ],

        'roundrobin' => [
            'transport' => 'roundrobin',
            'mailers' => [
                'ses',
                'postmark',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Global "From" Address
    |--------------------------------------------------------------------------
    |
    | You may wish for all e-mails sent by your application to be sent from
    | the same address. Here, you may specify a name and address that is
    | used globally for all e-mails that are sent by your application.
    |
    */

    'from' => [
        'address' => env('MAIL_FROM_ADDRESS', 'noreply@schoolsystem.com'),
        'name' => env('MAIL_FROM_NAME', 'School Management System'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Email Templates
    |--------------------------------------------------------------------------
    |
    | School system specific email templates
    |
    */

    'templates' => [
        'welcome' => [
            'subject' => 'Welcome to {school_name}',
            'view' => 'emails.welcome',
            'category' => 'welcome',
        ],
        'password_reset' => [
            'subject' => 'Password Reset Request',
            'view' => 'emails.password-reset',
            'category' => 'security',
        ],
        'student_registration' => [
            'subject' => 'Student Registration Confirmation',
            'view' => 'emails.student-registration',
            'category' => 'registration',
        ],
        'teacher_registration' => [
            'subject' => 'Teacher Account Created',
            'view' => 'emails.teacher-registration',
            'category' => 'registration',
        ],
        'parent_registration' => [
            'subject' => 'Parent Portal Access',
            'view' => 'emails.parent-registration',
            'category' => 'registration',
        ],
        'fee_payment' => [
            'subject' => 'Fee Payment Receipt',
            'view' => 'emails.fee-payment',
            'category' => 'financial',
        ],
        'exam_results' => [
            'subject' => 'Exam Results Published',
            'view' => 'emails.exam-results',
            'category' => 'academic',
        ],
        'attendance_report' => [
            'subject' => 'Monthly Attendance Report',
            'view' => 'emails.attendance-report',
            'category' => 'academic',
        ],
        'notice_board' => [
            'subject' => 'New Notice: {notice_title}',
            'view' => 'emails.notice-board',
            'category' => 'notification',
        ],
        'assignment_submitted' => [
            'subject' => 'Assignment Submitted',
            'view' => 'emails.assignment-submitted',
            'category' => 'academic',
        ],
        'assignment_graded' => [
            'subject' => 'Assignment Graded',
            'view' => 'emails.assignment-graded',
            'category' => 'academic',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Email Queues
    |--------------------------------------------------------------------------
    |
    | Configure email queue settings
    |
    */

    'queues' => [
        'default' => 'emails',
        'priority' => [
            'high' => ['password_reset', 'security'],
            'medium' => ['academic', 'financial'],
            'low' => ['notification', 'welcome'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Email Settings
    |--------------------------------------------------------------------------
    |
    | Additional email configuration
    |
    */

    'settings' => [
        'charset' => 'UTF-8',
        'encoding' => 'quoted-printable',
        'wordwrap' => 78,
        'priority' => 3, // Normal priority
        'auto_text' => true,
        'auto_html' => true,
        'track_opens' => env('MAIL_TRACK_OPENS', false),
        'track_clicks' => env('MAIL_TRACK_CLICKS', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Email Attachments
    |--------------------------------------------------------------------------
    |
    | Settings for email attachments
    |
    */

    'attachments' => [
        'max_size' => 10240, // 10MB
        'allowed_types' => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png'],
        'storage_disk' => 'public',
        'storage_path' => 'email-attachments',
    ],

    /*
    |--------------------------------------------------------------------------
    | School System Email Configuration
    |--------------------------------------------------------------------------
    |
    | School-specific email settings
    |
    */

    'school' => [
        'from_address' => env('SCHOOL_EMAIL_FROM', 'admin@schoolsystem.com'),
        'from_name' => env('SCHOOL_EMAIL_NAME', 'School Administration'),
        'reply_to' => env('SCHOOL_EMAIL_REPLY_TO', 'info@schoolsystem.com'),
        'bcc_admin' => env('SCHOOL_EMAIL_BCC_ADMIN', false),
        'bcc_address' => env('SCHOOL_EMAIL_BCC_ADDRESS'),
    ],

];