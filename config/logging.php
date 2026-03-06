<?php

use Monolog\Handler\NullHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\SyslogUdpHandler;
use Monolog\Processor\PsrLogMessageProcessor;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Log Channel
    |--------------------------------------------------------------------------
    |
    | This option defines the default log channel that gets used when writing
    | messages to the logs. The name specified in this option should match
    | one of the channels defined in the "channels" configuration array.
    |
    */

    'default' => env('LOG_CHANNEL', 'stack'),

    /*
    |--------------------------------------------------------------------------
    | Deprecations Log Channel
    |--------------------------------------------------------------------------
    |
    | This option controls the log channel that should be used to log warnings
    | regarding deprecated PHP and library features. This allows you to get
    | your application ready for upcoming major versions of dependencies.
    |
    */

    'deprecations' => [
        'channel' => env('LOG_DEPRECATIONS_CHANNEL', 'null'),
        'trace' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Log Channels
    |--------------------------------------------------------------------------
    |
    | Here you may configure the log channels for your application. Out of
    | the box, Laravel uses the Monolog PHP logging library. This gives
    | you a variety of powerful log handlers / formatters to utilize.
    |
    | Available Drivers: "single", "daily", "slack", "syslog",
    |                    "errorlog", "monolog",
    |                    "custom", "stack"
    |
    */

    'channels' => [
        'stack' => [
            'driver' => 'stack',
            'channels' => ['single', 'school'],
            'ignore_exceptions' => false,
        ],

        'single' => [
            'driver' => 'single',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'replace_placeholders' => true,
        ],

        'daily' => [
            'driver' => 'daily',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'days' => 14,
            'replace_placeholders' => true,
        ],

        'slack' => [
            'driver' => 'slack',
            'url' => env('LOG_SLACK_WEBHOOK_URL'),
            'username' => 'Laravel Log',
            'emoji' => ':boom:',
            'level' => env('LOG_LEVEL', 'critical'),
            'replace_placeholders' => true,
        ],

        'papertrail' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => env('LOG_PAPERTRAIL_HANDLER', SyslogUdpHandler::class),
            'handler_with' => [
                'host' => env('PAPERTRAIL_URL'),
                'port' => env('PAPERTRAIL_PORT'),
                'connectionString' => 'tls://'.env('PAPERTRAIL_URL').':'.env('PAPERTRAIL_PORT'),
            ],
            'processors' => [PsrLogMessageProcessor::class],
        ],

        'stderr' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => StreamHandler::class,
            'formatter' => env('LOG_STDERR_FORMATTER'),
            'with' => [
                'stream' => 'php://stderr',
            ],
            'processors' => [PsrLogMessageProcessor::class],
        ],

        'syslog' => [
            'driver' => 'syslog',
            'level' => env('LOG_LEVEL', 'debug'),
            'facility' => LOG_USER,
            'replace_placeholders' => true,
        ],

        'errorlog' => [
            'driver' => 'errorlog',
            'level' => env('LOG_LEVEL', 'debug'),
            'replace_placeholders' => true,
        ],

        'null' => [
            'driver' => 'monolog',
            'handler' => NullHandler::class,
        ],

        'emergency' => [
            'path' => storage_path('logs/laravel.log'),
        ],

        /*
        |--------------------------------------------------------------------------
        | School System Specific Log Channels
        |--------------------------------------------------------------------------
        */

        'school' => [
            'driver' => 'daily',
            'path' => storage_path('logs/school/system.log'),
            'level' => 'info',
            'days' => 30,
        ],

        'authentication' => [
            'driver' => 'daily',
            'path' => storage_path('logs/school/auth.log'),
            'level' => 'info',
            'days' => 90,
        ],

        'student' => [
            'driver' => 'daily',
            'path' => storage_path('logs/school/student.log'),
            'level' => 'info',
            'days' => 30,
        ],

        'teacher' => [
            'driver' => 'daily',
            'path' => storage_path('logs/school/teacher.log'),
            'level' => 'info',
            'days' => 30,
        ],

        'parent' => [
            'driver' => 'daily',
            'path' => storage_path('logs/school/parent.log'),
            'level' => 'info',
            'days' => 30,
        ],

        'financial' => [
            'driver' => 'daily',
            'path' => storage_path('logs/school/financial.log'),
            'level' => 'info',
            'days' => 365,
        ],

        'academic' => [
            'driver' => 'daily',
            'path' => storage_path('logs/school/academic.log'),
            'level' => 'info',
            'days' => 365,
        ],

        'security' => [
            'driver' => 'daily',
            'path' => storage_path('logs/school/security.log'),
            'level' => 'warning',
            'days' => 365,
        ],

        'audit' => [
            'driver' => 'daily',
            'path' => storage_path('logs/school/audit.log'),
            'level' => 'info',
            'days' => 365,
        ],

        'api' => [
            'driver' => 'daily',
            'path' => storage_path('logs/school/api.log'),
            'level' => 'debug',
            'days' => 7,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Log Context
    |--------------------------------------------------------------------------
    |
    | Additional context to include in logs
    |
    */

    'context' => [
        'include' => [
            'user_id',
            'school_id',
            'ip_address',
            'user_agent',
            'session_id',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Log Levels
    |--------------------------------------------------------------------------
    |
    | Custom log levels for school system
    |
    */

    'levels' => [
        'emergency' => 600,
        'alert' => 550,
        'critical' => 500,
        'error' => 400,
        'warning' => 300,
        'notice' => 250,
        'info' => 200,
        'debug' => 100,
        'trace' => 50,
    ],

    /*
    |--------------------------------------------------------------------------
    | Log Rotation
    |--------------------------------------------------------------------------
    |
    | Automatic log rotation settings
    |
    */

    'rotation' => [
        'enabled' => true,
        'max_size' => 10485760, // 10MB
        'compress_old' => true,
        'keep_days' => 90,
    ],

];