<?php
declare(strict_types=1);

namespace app\middleware;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use think\Request;
use think\Response;
use think\facade\Config;

class AdminAuth
{
    public function handle(Request $request, \Closure $next): Response
    {
        $token = $this->extractToken($request);

        if (empty($token)) {
            return json(['code' => 401, 'msg' => '未提供管理员认证令牌', 'data' => null], 401);
        }

        try {
            $secret = Config::get('points.admin_jwt.secret');
            $decoded = JWT::decode($token, new Key($secret, 'HS256'));
            $payload = (array) $decoded;

            if (($payload['role'] ?? '') !== 'admin') {
                return json(['code' => 403, 'msg' => '非管理员身份', 'data' => null], 403);
            }

            $request->adminId = $payload['sub'] ?? 0;
            $request->adminRole = $payload['admin_role'] ?? 'admin';
        } catch (\Exception $e) {
            return json(['code' => 401, 'msg' => '管理员认证失败', 'data' => null], 401);
        }

        return $next($request);
    }

    protected function extractToken(Request $request): ?string
    {
        $authorization = $request->header('Authorization', '');
        if (str_starts_with($authorization, 'Bearer ')) {
            return substr($authorization, 7);
        }
        return $request->param('token');
    }
}
