<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

class Announcement extends Model
{
    protected $table = 'announcements';
    protected $autoWriteTimestamp = 'datetime';
    protected $createTime = 'created_at';
    protected $updateTime = 'updated_at';

    protected $type = [
        'id' => 'integer',
        'status' => 'integer',
        'sort_order' => 'integer',
    ];

    // 查询范围：显示中
    public function scopeVisible($query)
    {
        $query->where('status', 1);
    }

    // 查询范围：按排序
    public function scopeOrdered($query)
    {
        $query->order('sort_order', 'desc')->order('id', 'desc');
    }
}
