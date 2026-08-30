<?php

return [
    'storage_disk' => env('REPORTING_STORAGE_DISK', 'local'),
    'file_ttl_minutes' => (int) env('REPORTING_FILE_TTL_MINUTES', 1440),
    'shared_link_max_minutes' => (int) env('REPORTING_SHARED_LINK_MAX_MINUTES', 60),
    'cleanup_schedule' => env('REPORTING_CLEANUP_SCHEDULE', 'hourly'),
];
