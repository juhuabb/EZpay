<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

class GamePrize extends Model
{
    protected $name = 'game_prizes';
    protected $autoWriteTimestamp = false;
    protected $createTime = 'created_at';
}
