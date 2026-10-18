<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

class GameConfig extends Model
{
    protected $name = 'game_configs';
    protected $autoWriteTimestamp = true;
    protected $createTime = 'created_at';
    protected $updateTime = 'updated_at';
}
