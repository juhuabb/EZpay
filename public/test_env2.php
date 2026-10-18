<?php
// /www/wwwroot/wjf.oooiooo.com/public/test_env2.php
// 手动加载 ThinkPHP 框架的 env
require __DIR__ . '/../vendor/autoload.php';

// 手动加载 .env
$dotenv = \think\facade\Env::load(dirname(__DIR__) . '/.env');

echo 'GETUI_APP_ID: ' . \think\facade\Env::get('GETUI_APP_ID', '(空)') . PHP_EOL;
echo 'GETUI_APP_KEY: ' . \think\facade\Env::get('GETUI_APP_KEY', '(空)') . PHP_EOL;
