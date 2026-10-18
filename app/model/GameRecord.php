<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

class GameRecord extends Model
{
    protected $name = 'game_records';
    protected $autoWriteTimestamp = false;
    protected $createTime = 'created_at';
}
