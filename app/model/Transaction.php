<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

class Transaction extends Model
{
    protected $table = 'transactions';
    protected $autoWriteTimestamp = 'datetime';
    protected $createTime = 'created_at';
    protected $updateTime = 'updated_at';

    protected $type = [
        'id' => 'integer',
        'order_id' => 'integer',
        'buyer_id' => 'integer',
        'seller_id' => 'integer',
        'merchant_id' => 'integer',
        'points_amount' => 'float',
        'total_price' => 'float',
        'status' => 'integer',
    ];

    // 关联订单
    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id', 'id');
    }

    // 关联买家
    public function buyer()
    {
        return $this->belongsTo(User::class, 'buyer_id', 'id');
    }

    // 关联卖家
    public function seller()
    {
        return $this->belongsTo(User::class, 'seller_id', 'id');
    }

    // 关联商户
    public function merchant()
    {
        return $this->belongsTo(Merchant::class, 'merchant_id', 'id');
    }

    // 交易状态机（以代码实际写入为准）：
    //   1 = 待付款      —— 买家已下单，线下付款中（买家不扣积分）
    //   2 = 交易中      —— 买家已确认付款，等待卖家确认收款
    //   3 = 已完成      —— 卖家确认收款 / 仲裁判买家胜，积分已放给买家
    //   4 = 已取消      —— 付款超时系统自动取消，或后台退款（OTC仅恢复订单上架）
    //   6 = 仲裁取消    —— 争议仲裁判卖家胜，交易取消、订单恢复上架
    //   7 = 争议冻结中  —— 卖家称未收到款，交易冻结待仲裁（complete→3 / cancel→6）
    //   8 = 买方取消    —— 买家在待付款阶段主动点击“取消交易”（订单恢复上架，非争议、不可申诉）
    public function getStatusTextAttr($value, $data): string
    {
        $map = [
            1 => '待付款',
            2 => '交易中',
            3 => '已完成',
            4 => '已取消',
            6 => '仲裁取消',
            7 => '争议冻结中',
            8 => '买方取消',
        ];
        return $map[$data['status']] ?? '未知';
    }
}
