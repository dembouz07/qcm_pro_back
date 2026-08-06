<?php

return [
    'hash_key' => env('ANALYTICS_HASH_KEY') ?: env('APP_KEY'),
    'schema_version' => 1,
    'metric_version' => '1.0',
    'metric_environment' => env('ANALYTICS_METRIC_ENVIRONMENT', env('APP_ENV', 'production')),
    'test_started_at' => env('ANALYTICS_TEST_STARTED_AT'),
    'internal_emails' => array_values(array_filter(array_map(
        fn (string $email) => strtolower(trim($email)),
        explode(',', (string) env('ANALYTICS_INTERNAL_EMAILS', '')),
    ))),
];
