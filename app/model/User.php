<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

class User extends Model
{
    protected $table = 'users';

    protected $autoWriteTimestamp = 'datetime';
    protected $createTime = 'created_at';
    protected $updateTime = 'updated_at';

    protected $type = [
        'id' => 'integer',
        'balance' => 'float',
        'principal' => 'float',
        'bonus' => 'float',
        'status' => 'integer',
        'real_name_verified' => 'integer',
        'merchant_verified' => 'integer',
    ];

    protected $hidden = [
        'password_hash', 'salt', 'login_password', 'pay_salt'
    ];

    // 关联实名认证
    public function verify()
    {
        return $this->hasOne(UserVerify::class, 'user_id', 'id');
    }

    // 关联商户
    public function merchant()
    {
        return $this->hasOne(Merchant::class, 'user_id', 'id');
    }

    // 关联订单
    public function orders()
    {
        return $this->hasMany(Order::class, 'user_id', 'id');
    }

    // 关联登录日志
    public function loginLogs()
    {
        return $this->hasMany(LoginLog::class, 'user_id', 'id');
    }

    public function scopeActive($query)
    {
        $query->where('status', 1);
    }

    public function scopeMerchants($query)
    {
        $query->where('role', 'merchant');
    }

    public function verifyPassword(string $password): bool
    {
        return password_verify($password, $this->password_hash);
    }

    public function verifyPayPassword(string $payPassword): bool
    {
        if (empty($this->login_password)) {
            return false;
        }
        $hash = md5($payPassword . $this->pay_salt);
        return password_verify($hash, $this->login_password);
    }

    public function setPayPassword(string $payPassword): void
    {
        $this->pay_salt = bin2hex(random_bytes(16));
        $hash = md5($payPassword . $this->pay_salt);
        $this->login_password = password_hash($hash, PASSWORD_BCRYPT);
    }

    public function isMerchant(): bool
    {
        return $this->role === 'merchant' && $this->merchant_verified === 1;
    }

    public function getAvailableBalanceAttr(): float
    {
        return (float)$this->balance;
    }

    /**
     * 生成35位钱包地址（Base58字符集，W前缀）
     * 种子 = 用户ID + 手机/邮箱 + 随机数，SHA-256哈希后映射到Base58
     */
    public static function generateWalletAddress(int $userId, string $phone = '', string $email = ''): string
    {
        // Base58字符集（排除0,O,I,l易混淆字符）
        $chars = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
        $charLen = strlen($chars);

        // 最多重试5次防止碰撞
        for ($attempt = 0; $attempt < 5; $attempt++) {
            // 种子：用户信息 + 随机数
            $seed = $userId . ($phone ?: '') . ($email ?: '') . bin2hex(random_bytes(32));
            $hash = hash('sha256', $seed);

            // W前缀 + 34位Base58字符 = 35位
            $address = 'W';
            for ($i = 0; $i < 34; $i++) {
                $hexPair = substr($hash, ($i * 2) % 64, 2);
                $address .= $chars[hexdec($hexPair) % $charLen];
            }

            // 检查唯一性
            if (!self::where('wallet_address', $address)->find()) {
                return $address;
            }
        }

        // 极端情况：5次都碰撞，加更多随机数
        $address = 'W' . substr(str_shuffle($chars . $chars . $chars), 0, 34);
        return $address;
    }

    /**
     * 生成9位推荐码（排除易混淆字符0,O,I,1）
     * 字符集：A-Z(去掉I,O) + 2-9 = 32种，32^9 ≈ 35万亿种组合
     */
    public static function generateReferralCode(): string
    {
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $charLen = strlen($chars);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $code = '';
            for ($i = 0; $i < 9; $i++) {
                $code .= $chars[random_int(0, $charLen - 1)];
            }
            if (!self::where('referral_code', $code)->find()) {
                return $code;
            }
        }

        // 极端情况兜底
        return substr(str_shuffle($chars . $chars . $chars), 0, 9);
    }
}