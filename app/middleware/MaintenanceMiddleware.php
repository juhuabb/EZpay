<?php

namespace app\middleware;

use think\Request;
use think\Response;
use think\facade\Db;

/**
 * 网络维护中间件
 * 检查 system_settings 中的 maintenance_mode，如果开启则拦截所有API请求
 */
class MaintenanceMiddleware
{
    public function handle(Request $request, \Closure $next)
    {
        try {
            $maintenanceMode = Db::name('system_settings')
                ->where('setting_key', 'maintenance_mode')
                ->value('setting_value');
            
            if ($maintenanceMode === '1' || $maintenanceMode === 1) {
                $message = Db::name('system_settings')
                    ->where('setting_key', 'maintenance_message')
                    ->value('setting_value');
                
                return json([
                    'code' => 503,
                    'msg' => $message ?: '系统维护中，请稍后再试',
                    'data' => null
                ]);
            }
        } catch (\Exception $e) {
            // 数据库异常时不阻断服务
        }
        
        return $next($request);
    }
}
