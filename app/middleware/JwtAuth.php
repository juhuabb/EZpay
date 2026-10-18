<?php
declare(strict_types=1);

namespace app\middleware;

use app\service\AuthService;
use think\Request;
use think\Response;

class JwtAuth
{
    protected AuthService $authService;

    public function __construct(AuthService $authService)
    {
        $this->authService = $authService;
    }

    public function handle(Request $request, \Closure $next): Response
    {
        $token = $this->extractToken($request);

        if (empty($token)) {
            return json(['code' => 401, 'msg' => '未提供认证令牌', 'data' => null], 401);
        }

        try {
            $payload = $this->authService->parseToken($token);

            if (($payload['type'] ?? '') !== 'access') {
                return json(['code' => 401, 'msg' => '无效的令牌类型', 'data' => null], 401);
            }

            // 将用户信息注入请求
            $request->uid = $payload['sub'] ?? 0;
            $request->userRole = $payload['role'] ?? 'user';
        } catch (\Exception $e) {
            return json(['code' => 401, 'msg' => '认证失败: ' . $e->getMessage(), 'data' => null], 401);
        }

        return $next($request);
    }

    /**
     * 从请求中提取Token
     */
    protected function extractToken(Request $request): ?string
    {
        $authorization = $request->header('Authorization', '');

        if (str_starts_with($authorization, 'Bearer ')) {
            return substr($authorization, 7);
        }

        return $request->param('token');
    }
}
