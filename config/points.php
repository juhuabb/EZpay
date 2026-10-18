<?php
declare(strict_types=1);

return [
    // JWT 配置
    'jwt' => [
        'secret' => env('POINTS_JWT_SECRET', ''),
        'issuer' => 'points-system',
        'audience' => 'points-client',
        'access_ttl' => 604800,     // Access Token 有效期(秒) 7天
        'refresh_ttl' => 2592000,   // Refresh Token 有效期(秒) 30天
        'algorithm' => 'HS256',
    ],

    // RSA 配置
    'rsa' => [
        'key_bits' => 2048,
        'private_type' => OPENSSL_KEYTYPE_RSA,
        'digest_algo' => 'sha256',
    ],

    // TOTP 配置
    'totp' => [
        'secret_length' => 20,
        'period' => 30,
        'digits' => 6,
        'algorithm' => 'sha1',
        'window' => 1,
        'issuer' => 'PointsSystem',
    ],


    // 管理员 JWT 配置
    'admin_jwt' => [
        'secret' => env('POINTS_ADMIN_JWT_SECRET', ''),
        'issuer' => 'points-system-admin',
        'access_ttl' => 604800,     // 7天
    ],

    // 密码策略
    'password' => [
        'min_length' => 8,
        'pay_min_length' => 6,
        'pay_max_length' => 6,
    ],

    // 交易配置
    'trade' => [
        'min_amount' => 10.00,
        'max_amount' => 10000.00,
        'default_fee_rate' => 0.02,
    ],

    // TRC20充值配置
    'tron' => [
        'receive_address' => env('TRON_RECEIVE_ADDRESS', ''),
        'api_key' => env('TRON_API_KEY', ''),
        'usdt_to_points' => floatval(env('USDT_TO_POINTS_RATE', '7')),
        'trx_to_points' => floatval(env('TRX_TO_POINTS_RATE', '50')),
        'min_amount' => floatval(env('TRON_MIN_AMOUNT', '1')),
        'max_amount' => floatval(env('TRON_MAX_AMOUNT', '10000')),
        'expire_minutes' => intval(env('TRON_EXPIRE_MINUTES', '30')),
    ],
];
