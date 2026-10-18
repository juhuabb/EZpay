<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

class AdminUser extends Model
{
    protected $table = 'admin_users';
    protected $autoWriteTimestamp = 'datetime';
    protected $createTime = 'created_at';
    protected $updateTime = false;

    protected $type = [
        'id' => 'integer',
        'status' => 'integer',
    ];

    protected $hidden = [
        'password_hash'
    ];

    // 验证密码
    public function verifyPassword(string $password): bool
    {
        return password_verify($password, $this->password_hash);
    }

    // 查询范围：正常
    public function scopeActive($query)
    {
        $query->where('status', 1);
    }
}
