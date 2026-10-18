<?php
// 模拟 ThinkPHP 的 env() 解析
$envFile = dirname(__DIR__) . '/.env';
$lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$env = [];
foreach ($lines as $line) {
    if (strpos(trim($line), '#') === 0) continue;
    if (strpos($line, '=') === false) continue;
    list($key, $value) = array_map('trim', explode('=', $line, 2));
    $env[$key] = $value;
}
echo 'GETUI_APP_ID: ' . ($env['GETUI_APP_ID'] ?? '(不存在)') . PHP_EOL;
echo 'GETUI_APP_KEY: ' . ($env['GETUI_APP_KEY'] ?? '(不存在)') . PHP_EOL;
echo 'GETUI_MASTER_SECRET: ' . ($env['GETUI_MASTER_SECRET'] ?? '(不存在)') . PHP_EOL;
echo PHP_EOL . '--- 文件编码检查 ---' . PHP_EOL;
$raw = file_get_contents($envFile);
$bytes = substr($raw, 0, 3);
if (ord($bytes[0]) == 0xEF && ord($bytes[1]) == 0xBB && ord($bytes[2]) == 0xBF) {
    echo '⚠️ .env 文件有 BOM 头！这会导致 ThinkPHP 读取失败！';
} else {
    echo '✅ .env 文件无 BOM';
}
echo PHP_EOL . '最后100字节十六进制:' . PHP_EOL;
echo bin2hex(substr($raw, -100)) . PHP_EOL;
