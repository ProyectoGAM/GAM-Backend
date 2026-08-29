<?php

use App\Models\AuditAndTraceability\AuditEntry;
use Spatie\Activitylog\Actions\CleanActivityLogAction;
use Spatie\Activitylog\Actions\LogActivityAction;

return [
    'enabled' => env('ACTIVITYLOG_ENABLED', true),
    'clean_after_days' => (int) env('ACTIVITYLOG_CLEAN_AFTER_DAYS', 2555),
    'default_log_name' => 'default',
    'default_auth_driver' => null,
    'include_soft_deleted_subjects' => true,
    'activity_model' => AuditEntry::class,
    'default_except_attributes' => [
        'password',
        'password_confirmation',
        'remember_token',
        'access_token',
        'token',
        'secret',
    ],
    'buffer' => [
        'enabled' => env('ACTIVITYLOG_BUFFER_ENABLED', false),
    ],
    'actions' => [
        'log_activity' => LogActivityAction::class,
        'clean_log' => CleanActivityLogAction::class,
    ],
];
