<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['*'],
    'allowed_origins' => ['*'],
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    // المصادقة بتوكن Bearer لا بالكوكيز — لا حاجة لبيانات اعتماد المتصفح،
    // وتركيبة ('*' + credentials:true) مخالفة لمواصفة CORS أصلاً.
    'supports_credentials' => false,
];
