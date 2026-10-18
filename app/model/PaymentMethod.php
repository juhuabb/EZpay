<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

class PaymentMethod extends Model
{
    protected $table = 'payment_methods';
    protected $autoWriteTimestamp = false;
    protected $createTime = 'created_at';

    protected $type = [
        'id' => 'integer',
        'merchant_id' => 'integer',
        'is_default' => 'integer',
    ];

    // 关联商户
    public function merchant()
    {
        return $this->belongsTo(Merchant::class, 'merchant_id', 'id');
    }

    // 查询范围：默认
    public function scopeDefault($query)
    {
        $query->where('is_default', 1);
    }

    // 类型文字
    public function getTypeTextAttr($value, $data): string
    {
        $map = ['bank' => '银行卡', 'alipay' => '支付宝', 'wechat' => '微信'];
        return $map[$data['type']] ?? '未知';
    }
}
