<?php
declare(strict_types=1);

// Session配置
return [
    // session name
    'name' => 'PHPSESSID',
    // session 缓存方式
    'type' => 'file',
    // session 缓存前缀
    'prefix' => 'think',
    // session 过期时间
    'expire' => 7200,
    // session 存储路径
    'path' => '',
    // 是否 httponly
    'httponly' => true,
    // 是否 secure
    'secure' => false,
];
