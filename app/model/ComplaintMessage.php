<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

class ComplaintMessage extends Model
{
    protected $name = 'complaint_message';
    protected $autoWriteTimestamp = 'datetime';
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';
}