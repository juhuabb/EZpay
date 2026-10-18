<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

class SystemSetting extends Model
{
    protected $table = 'system_settings';
    protected $autoWriteTimestamp = 'datetime';
    protected $createTime = 'created_at';
    protected $updateTime = 'updated_at';

    protected $type = [
        'id' => 'integer',
    ];

    /**
     * 获取设置值
     */
    public static function getValue(string $key, $default = null)
    {
        $setting = self::where('setting_key', $key)->find();
        return $setting ? $setting->setting_value : $default;
    }

    /**
     * 设置值
     */
    public static function setValue(string $key, string $value, string $description = ''): void
    {
        $setting = self::where('setting_key', $key)->find();
        if ($setting) {
            $setting->save(['setting_value' => $value]);
        } else {
            self::create([
                'setting_key' => $key,
                'setting_value' => $value,
                'description' => $description,
            ]);
        }
    }

    /**
     * 批量获取设置
     */
    public static function getMultiple(array $keys): array
    {
        $settings = self::whereIn('setting_key', $keys)->select();
        $result = [];
        foreach ($settings as $setting) {
            $result[$setting->setting_key] = $setting->setting_value;
        }
        return $result;
    }
}
