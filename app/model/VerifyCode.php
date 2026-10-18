<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

class VerifyCode extends Model
{
    protected $table = 'verify_codes';
    protected $autoWriteTimestamp = false;

    protected $type = [
        'id' => 'integer',
        'used' => 'integer',
    ];

    /**
     * 创建验证码记录
     */
    public static function createCode(string $target, string $code, string $type, string $scene = 'register', string $ip = '', int $ttl = 600): self
    {
        return self::create([
            'target' => $target,
            'code' => $code,
            'type' => $type,
            'scene' => $scene,
            'used' => 0,
            'ip' => $ip,
            'expired_at' => date('Y-m-d H:i:s', time() + $ttl),
        ]);
    }

    /**
     * 验证并消耗验证码
     */
    public static function verify(string $target, string $code, string $scene = 'register'): bool
    {
        $record = self::where('target', $target)
            ->where('code', $code)
            ->where('scene', $scene)
            ->where('used', 0)
            ->where('expired_at', '>', date('Y-m-d H:i:s'))
            ->order('id', 'desc')
            ->find();

        if (!$record) {
            return false;
        }

        $record->save(['used' => 1]);
        return true;
    }

    /**
     * 检查发送频率限制
     * @return string|null 错误信息，null表示可以发送
     */
    public static function checkRateLimit(string $target, string $ip): ?string
    {
        // 同一号码/邮箱 60秒内只能发1次
        $recent = self::where('target', $target)
            ->where('created_at', '>', date('Y-m-d H:i:s', time() - 60))
            ->count();
        if ($recent > 0) {
            return '发送太频繁，请60秒后再试';
        }

        // 同一号码/邮箱 1小时最多10次
        $hourly = self::where('target', $target)
            ->where('created_at', '>', date('Y-m-d H:i:s', time() - 3600))
            ->count();
        if ($hourly >= 10) {
            return '该号码/邮箱发送次数已达上限，请1小时后再试';
        }

        // 同一IP 1小时最多20次
        if ($ip) {
            $ipCount = self::where('ip', $ip)
                ->where('created_at', '>', date('Y-m-d H:i:s', time() - 3600))
                ->count();
            if ($ipCount >= 20) {
                return '操作太频繁，请稍后再试';
            }
        }

        return null;
    }
}
