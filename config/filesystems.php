<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application. Just store away!
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Here you may configure as many filesystem "disks" as you wish, and you
    | may even configure multiple disks of the same driver. Defaults have
    | been set up for each driver as an example of the required values.
    |
    | Supported Drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app'),
            'throw' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => env('APP_URL').'/storage',
            'visibility' => 'public',
            'throw' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
        ],

        'ftp' => [
            'driver' => 'ftp',
            'host' => env('FTP_HOST'),
            'username' => env('FTP_USERNAME'),
            'password' => env('FTP_PASSWORD'),
            'port' => env('FTP_PORT', 21),
            'root' => env('FTP_ROOT'),
            'passive' => true,
            'ssl' => true,
            'timeout' => 30,
        ],

        'sftp' => [
            'driver' => 'sftp',
            'host' => env('SFTP_HOST'),
            'username' => env('SFTP_USERNAME'),
            'password' => env('SFTP_PASSWORD'),
            'privateKey' => env('SFTP_PRIVATE_KEY'),
            'passphrase' => env('SFTP_PASSPHRASE'),
            'port' => env('SFTP_PORT', 22),
            'root' => env('SFTP_ROOT'),
            'timeout' => 30,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

    /*
    |--------------------------------------------------------------------------
    | School System Specific Storage
    |--------------------------------------------------------------------------
    |
    | Custom storage paths for school management system
    |
    */

    'school' => [
        'students' => [
            'avatars' => 'schools/{school_id}/students/avatars',
            'documents' => 'schools/{school_id}/students/documents',
            'results' => 'schools/{school_id}/students/results',
            'assignments' => 'schools/{school_id}/students/assignments',
        ],
        'teachers' => [
            'avatars' => 'schools/{school_id}/teachers/avatars',
            'documents' => 'schools/{school_id}/teachers/documents',
            'lesson_plans' => 'schools/{school_id}/teachers/lesson-plans',
            'assignments' => 'schools/{school_id}/teachers/assignments',
        ],
        'schools' => [
            'logos' => 'schools/{school_id}/logos',
            'documents' => 'schools/{school_id}/documents',
            'reports' => 'schools/{school_id}/reports',
            'notices' => 'schools/{school_id}/notices',
        ],
        'classes' => [
            'materials' => 'schools/{school_id}/classes/{class_id}/materials',
            'assignments' => 'schools/{school_id}/classes/{class_id}/assignments',
            'results' => 'schools/{school_id}/classes/{class_id}/results',
        ],
        'exams' => [
            'papers' => 'schools/{school_id}/exams/{exam_id}/papers',
            'results' => 'schools/{school_id}/exams/{exam_id}/results',
            'reports' => 'schools/{school_id}/exams/{exam_id}/reports',
        ],
        'payments' => [
            'receipts' => 'schools/{school_id}/payments/receipts',
            'invoices' => 'schools/{school_id}/payments/invoices',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | File Upload Settings
    |--------------------------------------------------------------------------
    |
    | Configuration for file uploads
    |
    */

    'uploads' => [
        'max_size' => 20480, // 20MB in KB
        'allowed_types' => [
            'images' => ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'svg', 'webp'],
            'documents' => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt'],
            'archives' => ['zip', 'rar', '7z'],
            'media' => ['mp3', 'mp4', 'avi', 'mov', 'wmv'],
        ],
        'image' => [
            'max_width' => 1920,
            'max_height' => 1080,
            'quality' => 85,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Backup Configuration
    |--------------------------------------------------------------------------
    |
    | Settings for database and file backups
    |
    */

    'backup' => [
        'path' => storage_path('app/backups'),
        'retention_days' => 30,
        'compress' => true,
        'encrypt' => false,
    ],

];