<?php
declare(strict_types=1);

namespace app\service;

use app\model\SystemSetting;
use think\facade\Log;

class SmsService
{
    /**
     * 发送短信验证码
     * @param string $phone 手机号
     * @param string $code 验证码
     * @return bool
     */
    public function sendVerifyCode(string $phone, string $code): bool
    {
        $uid = SystemSetting::getValue('sms_uid', '');
        $key = SystemSetting::getValue('sms_key', '');
        $sign = SystemSetting::getValue('sms_sign', '积分平台');

        if (empty($uid) || empty($key)) {
            Log::error('短信配置缺失: sms_uid 或 sms_key');
            return false;
        }

        $smsText = urlencode("【{$sign}】验证码：{$code}，10分钟内有效，请勿泄露。");
        $url = 'https://utf8api.smschinese.cn/';
        $post = 'Uid=' . urlencode($uid) . '&Key=' . urlencode($key) . '&smsMob=' . urlencode($phone) . '&smsText=' . $smsText;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $result = curl_exec($ch);
        $errno = curl_errno($ch);
        unset($ch);

        if ($errno) {
            Log::error("短信发送失败: curl error {$errno}");
            return false;
        }

        // 返回值大于0表示发送成功
        if (is_numeric($result) && (int)$result > 0) {
            Log::info("短信发送成功: {$phone}");
            return true;
        }

        Log::error("短信发送失败: phone={$phone}, result={$result}");
        return false;
    }

    /**
     * 生成随机验证码
     */
    public function generateCode(int $length = 6): string
    {
        $code = '';
        for ($i = 0; $i < $length; $i++) {
            $code .= random_int(0, 9);
        }
        return $code;
    }
}
