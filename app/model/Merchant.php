<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

class Merchant extends Model
{
    protected $table = 'merchants';
    protected $autoWriteTimestamp = 'datetime';
    protected $createTime = 'created_at';
    protected $updateTime = 'updated_at';

    protected $type = [
        'id' => 'integer',
        'user_id' => 'integer',
        'payment_enabled' => 'integer',
        'payment_limit' => 'float',
        'status' => 'integer',
    ];

    protected $hidden = [
        'totp_secret', 'api_secret', 'rsa_private_key'
    ];

    // 关联用户
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    // 关联订单
    public function orders()
    {
        return $this->hasMany(Order::class, 'merchant_id', 'id');
    }

    // 关联收款方式
    public function paymentMethods()
    {
        return $this->hasMany(PaymentMethod::class, 'merchant_id', 'id');
    }

    // 关联交易
    public function transactions()
    {
        return $this->hasMany(Transaction::class, 'merchant_id', 'id');
    }

    // 查询范围：正常
    public function scopeActive($query)
    {
        $query->where('status', 1);
    }

    // 查询范围：支付功能开启
    public function scopePaymentEnabled($query)
    {
        $query->where('payment_enabled', 1);
    }

    // 是否可用
    public function isActive(): bool
    {
        return $this->status === 1;
    }

    // 生成API密钥
    public function generateApiCredentials(): void
    {
        $this->api_key = bin2hex(random_bytes(32));
        $this->api_secret = bin2hex(random_bytes(64));
    }
}
