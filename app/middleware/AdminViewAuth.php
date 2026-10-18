<?php
declare(strict_types=1);

namespace app\middleware;

use think\Request;
use think\Response;
use think\facade\Session;

class AdminViewAuth
{
    /**
     * 后台视图认证中间件
     * 检查session中是否有管理员登录信息，未登录重定向到登录页
     */
    public function handle(Request $request, \Closure $next): Response
    {
        // 登录页面不经过此中间件
        if ($request->pathInfo() === 'admin/login') {
            return $next($request);
        }

        // 检查session中是否有管理员信息
        $adminId = Session::get('admin_id');

        if (empty($adminId)) {
            return redirect('/admin/login');
        }

        return $next($request);
    }
}
