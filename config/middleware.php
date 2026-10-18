<?php
declare(strict_types=1);

return [
    'alias' => [
        'jwt_auth' => \app\middleware\JwtAuth::class,
        'admin_auth' => \app\middleware\AdminAuth::class,
        'admin_view_auth' => \app\middleware\AdminViewAuth::class,
        'merchant_api_auth' => \app\middleware\MerchantApiAuth::class,
        'cors' => \app\middleware\CorsMiddleware::class,
    ],
    'priority' => [
        \app\middleware\CorsMiddleware::class,
        \app\middleware\JwtAuth::class,
        \app\middleware\AdminAuth::class,
        \app\middleware\AdminViewAuth::class,
        \app\middleware\MerchantApiAuth::class,
    ],
];
