<?php
declare(strict_types=1);

namespace app\middleware;

use think\Request;
use think\Response;
use think\facade\Session;

class AppTokenAuth
{
    public function handle(Request $request, \Closure $next): Response
    {
        if (Session::get('admin_uid')) {
            return $next($request);
        }

        $token = $request->header('X-Admin-Token', '');
        if (empty($token)) {
            return $next($request);
        }

        $decoded = base64_decode($token, true);
        if ($decoded === false) {
            return $next($request);
        }

        $payload = json_decode($decoded, true);
        if (!$payload || !isset($payload['uid']) || !isset($payload['exp'])) {
            return $next($request);
        }

        if ($payload['exp'] < time()) {
            return $next($request);
        }

        Session::set('admin_uid', (int)$payload['uid']);
        if (isset($payload['username'])) {
            Session::set('admin_username', $payload['username']);
        }

        return $next($request);
    }
}
