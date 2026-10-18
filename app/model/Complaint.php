<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

class Complaint extends Model
{
    protected $table = 'complaint';
    protected $autoWriteTimestamp = true;

    public function buyer()
    {
        return $this->belongsTo(User::class, 'buyer_id', 'id');
    }

    public function seller()
    {
        return $this->belongsTo(User::class, 'seller_id', 'id');
    }

    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id', 'id');
    }

    public function transaction()
    {
        return $this->belongsTo(Transaction::class, 'transaction_id', 'id');
    }
}
