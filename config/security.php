<?php

return [
    'admin_login_path' => trim((string) env('ADMIN_LOGIN_PATH', 'utica-admin-entry'), '/'),
    'admin_idle_minutes' => (int) env('ADMIN_IDLE_MINUTES', 15),
    'password_confirmation_seconds' => (int) env('PASSWORD_CONFIRMATION_SECONDS', 300),
    'audit_retention_days' => (int) env('SECURITY_AUDIT_RETENTION_DAYS', 180),
    'mfa' => [
        'issuer' => env('MFA_ISSUER', 'U-Mail'),
    ],
    'turnstile' => [
        'enabled' => env('TURNSTILE_ENABLED', false),
        'site_key' => env('TURNSTILE_SITE_KEY'),
        'secret_key' => env('TURNSTILE_SECRET_KEY'),
        'failure_threshold' => (int) env('TURNSTILE_FAILURE_THRESHOLD', 3),
    ],
];
