<?php
declare(strict_types=1);

// 视图配置
return [
    // 模板引擎类型
    'type' => 'Think',
    // 模板后缀
    'view_suffix' => 'html',
    // 模板文件名分隔符
    'view_depr' => DIRECTORY_SEPARATOR,
    // 是否开启模板编译缓存,设为false则每次都会重新编译
    'tpl_cache' => true,
    // 视图基础路径（指向view/admin/目录，解决控制器命名空间导致的路径不匹配问题）
    'view_path' => app()->getRootPath() . 'view/admin/',
];
