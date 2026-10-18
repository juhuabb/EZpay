<?php
declare(strict_types=1);
namespace app\model;
use think\Model;
class Broadcast extends Model
{
    protected $name = 'broadcasts';
    protected $autoWriteTimestamp = 'datetime';
    protected $createTime = 'created_at';
    protected $updateTime = 'updated_at';
}
