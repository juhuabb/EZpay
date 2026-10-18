<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

class Order extends Model
{
    protected $table = 'orders';
    protected $autoWriteTimestamp = 'datetime';
    protected $createTime = 'created_at';
    protected $updateTime = 'updated_at';

    protected $type = [
        'id' => 'integer',
        'merchant_id' => 'integer',
        'user_id' => 'integer',
        'points_amount' => 'float',
        'unit_price' => 'float',
        'total_amount' => 'float',
        'remaining_amount' => 'float',
        'status' => 'integer',
    ];

    // 关联商户
    public function merchant()
    {
        return $this->belongsTo(Merchant::class, 'merchant_id', 'id');
    }

    // 关联创建者
    public function creator()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    // 关联交易
    public function transactions()
    {
        return $this->hasMany(Transaction::class, 'order_id', 'id');
    }

    // 查询范围：上架中
    public function scopeOnSale($query)
    {
        $query->where('status', 1);
    }

    // 查询范围：已完成
    public function scopeCompleted($query)
    {
        $query->where('status', 3);
    }

    // 挂单状态机（orders.status，以代码实际写入为准）：
    //   1 = 上架中（可购买）；3 = 已完成（积分全部售罄）；4 = 已下架（卖家删除挂单）；
    //   2 = 已暂停（预留状态，当前业务流程暂无赋值点）
    public function getStatusTextAttr($value, $data): string
    {
        $map = [1 => '上架中', 2 => '已暂停', 3 => '已完成', 4 => '已下架'];
        return $map[$data['status']] ?? '未知';
    }

    // 是否可购买
    public function isPurchasable(): bool
    {
        return $this->status === 1 && $this->remaining_amount > 0;
    }
}
