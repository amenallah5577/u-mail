<?php

return [
    'public_domain' => strtolower((string) env('PUBLIC_MAIL_DOMAIN', 'u-mail.local')),
    'message_id_domain' => strtolower((string) env('MAIL_MESSAGE_ID_DOMAIN', 'u-mail.local')),
    'mailcow_enabled' => (bool) env('MAILCOW_ENABLED', false),
    'external_queue' => env('EXTERNAL_MAIL_QUEUE', 'external-mail'),
    'critical_queue' => env('CRITICAL_MAIL_QUEUE', 'critical-mail'),
    'max_incoming_bytes' => (int) env('MAX_INCOMING_MAIL_BYTES', 31457280),
    'max_attachment_bytes' => (int) env('MAX_INCOMING_ATTACHMENT_BYTES', 26214400),
    'dangerous_extensions' => [
        'bat', 'cmd', 'com', 'cpl', 'exe', 'hta', 'jar', 'js', 'jse', 'lnk', 'msi',
        'ps1', 'reg', 'scr', 'vbe', 'vbs', 'wsf',
    ],
];
