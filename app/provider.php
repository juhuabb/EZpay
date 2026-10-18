<?php
declare(strict_types=1);

use app\ExceptionHandle;

// 容器Provider定义文件
return [
    'think\exception\Handle' => ExceptionHandle::class,
];
