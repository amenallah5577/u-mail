<?php

return [
    'enabled' => env('AI_FEATURES_ENABLED', false),
    'provider' => env('AI_PROVIDER', 'none'),
    'local_endpoint' => env('AI_LOCAL_ENDPOINT', 'http://127.0.0.1:11434'),
    'local_model' => env('AI_LOCAL_MODEL', 'llama3.2'),
];
