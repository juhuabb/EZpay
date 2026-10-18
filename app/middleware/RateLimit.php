<?php
declare(strict_types=1);

namespace app\middleware;

use think\Request;
use think\Response;
use think\facade\Cache;

/**
 * API 限流中间件
 * 基于 IP + URI 的请求频率限制
 * 用法：在路由中 ->middleware(\app\middleware\RateLimit::class, ['max' => 60, 'window' => 60])
 * 默认：60秒内最多60次请求
 */
class RateLimit
{
    public function handle(Request $request, \Closure $next, $max = 60, $window = 60): Response
    {
        $ip = $request->ip();
        $uri = $request->pathinfo();
        $key = 'rate_limit:' . md5($ip . ':' . $uri);

        $count = Cache::inc($key);
        if ($count === 1) {
            Cache::expire($key, (int)$window);
        }

        if ($count > (int)$max) {
            return json([
                'code' => 429,
                'msg' => '请求过于频繁，请稍后再试',
                'data' => null
            ], 429);
        }

        $response = $next($request);
        $response->header([
            'X-RateLimit-Limit' => (int)$max,
            'X-RateLimit-Remaining' => max(0, (int)$max - (int)$count),
        ]);

        return $response;
    }
}
