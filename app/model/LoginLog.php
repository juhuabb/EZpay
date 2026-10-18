<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

class LoginLog extends Model
{
    protected $table = 'login_logs';
    protected $autoWriteTimestamp = false;
    protected $createTime = 'created_at';
    protected $updateTime = false;

    protected $type = [
        'id' => 'integer',
        'user_id' => 'integer',
        'status' => 'integer',
    ];

    // 关联用户
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    // 记录登录日志
    public static function record(int $userId, string $ip, string $userAgent, string $loginType, int $status): void
    {
        self::create([
            'user_id' => $userId,
            'ip' => $ip,
            'user_agent' => substr($userAgent, 0, 500),
            'login_type' => $loginType,
            'status' => $status,
        ]);
    }
}
