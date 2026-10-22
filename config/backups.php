<?php

return [
    'directory' => storage_path('app/backups'),
    'work_directory' => storage_path('app/backup-work'),
    'time' => '02:00',
    'timezone' => 'Asia/Riyadh',
    'maximum_restore_bytes' => 5 * 1024 * 1024 * 1024,
];
