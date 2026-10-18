<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

class UserVerify extends Model
{
    protected $table = 'user_verify';
    protected $autoWriteTimestamp = 'datetime';
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

    // 查询范围：待审核
    public function scopePending($query)
    {
        $query->where('status', 0);
    }

    // 查询范围：已通过
    public function scopeApproved($query)
    {
        $query->where('status', 1);
    }

    // 状态描述
    public function getStatusTextAttr($value, $data): string
    {
        $map = [0 => '待审核', 1 => '已通过', 2 => '已拒绝'];
        return $map[$data['status']] ?? '未知';
    }
}
