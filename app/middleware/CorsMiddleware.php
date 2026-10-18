<?php
declare(strict_types=1);

namespace app\middleware;

use think\Request;
use think\Response;

class CorsMiddleware
{
    public function handle(Request $request, \Closure $next): Response
    {
        // 处理 OPTIONS 预检请求
        if ($request->method(true) === 'OPTIONS') {
            $response = Response::create('', 'html', 204);
        } else {
            $response = $next($request);
        }

        // 反射 Origin：浏览器发什么就允许什么
        $origin = $request->header('Origin', '');
        $allowOrigin = $origin ?: '*';

        $response->header([
            'Access-Control-Allow-Origin' => $allowOrigin,
            'Access-Control-Allow-Methods' => 'GET, POST, PUT, DELETE, PATCH, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, Authorization, X-Requested-With, X-Api-Key, X-Timestamp, X-Signature, Accept, Origin',
            'Access-Control-Allow-Credentials' => 'true',
            'Access-Control-Max-Age' => '86400',
        ]);

        return $response;
    }
}
