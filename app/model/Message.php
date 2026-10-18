<?php
declare(strict_types=1);
namespace app\model;
use think\Model;
class Message extends Model
{
    protected $name = 'messages';
    protected $autoWriteTimestamp = 'datetime';
    protected $createTime = 'created_at';
    protected $updateTime = 'updated_at';
}
