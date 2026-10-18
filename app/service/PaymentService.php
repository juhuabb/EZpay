<?php
declare(strict_types=1);

namespace app\service;

use app\model\Merchant;
use think\facade\Db;

class PaymentService
{
    /**
     * 通知商户支付结果
     * @return array ['success'=>bool, 'http_code'=>int, 'response'=>string, 'error'=>string]
     */
    public static function notifyMerchant(int $orderId): array
    {
        $order = Db::name('payment_orders')->find($orderId);
        if (!$order) {
            return ['success' => false, 'http_code' => 0, 'response' => '', 'error' => '订单不存在'];
        }
        if (empty($order['notify_url'])) {
            return ['success' => false, 'http_code' => 0, 'response' => '', 'error' => '该订单未设置通知地址'];
        }

        $merchant = Merchant::find($order['merchant_id']);
        if (!$merchant) {
            return ['success' => false, 'http_code' => 0, 'response' => '', 'error' => '商户不存在'];
        }

        // ★修复：发送前再次校验notify_url安全性（防SSRF）
        $urlCheck = self::validateNotifyUrl($order['notify_url']);
        if (!$urlCheck['valid']) {
            return ['success' => false, 'http_code' => 0, 'response' => '', 'error' => 'notify_url不安全: ' . $urlCheck['error']];
        }

        // 获取平台RSA私钥用于签名
        $platformPrivateKey = self::getPlatformPrivateKey();

        // 构建通知数据
        $notifyData = [
            'order_no' => $order['order_no'],
            'out_trade_no' => $order['out_trade_no'],
            'amount' => (float)$order['amount'],
            'status' => (int)$order['status'],
            'paid_at' => $order['paid_at'],
            'timestamp' => time(),
        ];

        $dataStr = json_encode($notifyData, JSON_UNESCAPED_UNICODE);

        // 用平台私钥签名
        $signature = '';
        if ($platformPrivateKey) {
            $rsaService = new RsaService();
            try {
                $signature = $rsaService->sign($dataStr, $platformPrivateKey);
            } catch (\Throwable $e) {
                // 签名失败继续发送
            }
        }

        // 发送POST通知
        $payload = json_encode([
            'data' => $notifyData,
            'signature' => $signature,
        ], JSON_UNESCAPED_UNICODE);

        $ch = curl_init($order['notify_url']);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'X-Platform-Signature: ' . $signature,
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        // ★修复：开启SSL证书验证（防MITM）
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        // ★修复：设置连接超时
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        // ★修复：禁止重定向（防止SSRF跳转到内网）
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        // ★修复：限制协议为http/https
        curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
        // ★修复：设置User-Agent标识
        curl_setopt($ch, CURLOPT_USERAGENT, 'WJFPay-Platform-Notify/1.0');

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        // 更新通知状态
        $notifySuccess = ($httpCode >= 200 && $httpCode < 300);

        try {
            Db::name('payment_orders')->where('id', $orderId)->update([
                'notify_status' => $notifySuccess ? 1 : 2,
                'notify_count' => ($order['notify_count'] ?? 0) + 1,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // 忽略列不存在等异常
        }

        return [
            'success' => $notifySuccess,
            'http_code' => (int)$httpCode,
            'response' => is_string($response) ? mb_substr($response, 0, 500) : '',
            'error' => $error ?: '',
        ];
    }

    /**
     * ★新增：校验通知URL安全性（防SSRF）
     */
    private static function validateNotifyUrl(string $url): array
    {
        $parsed = parse_url($url);
        if ($parsed === false || !isset($parsed['scheme']) || !isset($parsed['host'])) {
            return ['valid' => false, 'error' => 'URL格式不正确'];
        }

        $scheme = strtolower($parsed['scheme']);
        if (!in_array($scheme, ['http', 'https'])) {
            return ['valid' => false, 'error' => '仅允许http/https协议'];
        }

        $host = $parsed['host'];

        // 解析域名获取IP
        $ips = @gethostbynamel($host);
        if ($ips === false) {
            if (filter_var($host, FILTER_VALIDATE_IP)) {
                $ips = [$host];
            } else {
                return ['valid' => false, 'error' => '域名无法解析'];
            }
        }

        // 检查每个IP是否为内网/保留地址
        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return ['valid' => false, 'error' => 'URL指向内网或保留地址'];
            }
        }

        return ['valid' => true, 'error' => ''];
    }

    /**
     * 获取平台RSA私钥（不存在则自动生成）
     */
    public static function getPlatformPrivateKey(): string
    {
        $key = Db::name('system_settings')->where('setting_key', 'platform_rsa_private_key')->value('setting_value');
        if (empty($key)) {
            self::ensurePlatformKeyPair();
            $key = Db::name('system_settings')->where('setting_key', 'platform_rsa_private_key')->value('setting_value');
        }
        return $key ?: '';
    }

    /**
     * 获取平台RSA公钥
     */
    public static function getPlatformPublicKey(): string
    {
        $key = Db::name('system_settings')->where('setting_key', 'platform_rsa_public_key')->value('setting_value');
        if (empty($key)) {
            self::ensurePlatformKeyPair();
            $key = Db::name('system_settings')->where('setting_key', 'platform_rsa_public_key')->value('setting_value');
        }
        return $key ?: '';
    }

    /**
     * 确保平台RSA密钥对存在
     */
    public static function ensurePlatformKeyPair(): void
    {
        $exists = Db::name('system_settings')->where('setting_key', 'platform_rsa_private_key')->find();
        if ($exists) {
            return;
        }

        $rsaService = new RsaService();
        $keyPair = $rsaService->generateKeyPair();

        Db::name('system_settings')->insert([
            'setting_key' => 'platform_rsa_private_key',
            'setting_value' => $keyPair['private_key'],
        ]);
        Db::name('system_settings')->insert([
            'setting_key' => 'platform_rsa_public_key',
            'setting_value' => $keyPair['public_key'],
        ]);
    }

    /**
     * 重新生成平台RSA密钥对
     */
    public static function regeneratePlatformKeyPair(): array
    {
        $rsaService = new RsaService();
        $keyPair = $rsaService->generateKeyPair();

        $privateExists = Db::name('system_settings')->where('setting_key', 'platform_rsa_private_key')->find();
        if ($privateExists) {
            Db::name('system_settings')->where('setting_key', 'platform_rsa_private_key')->update(['setting_value' => $keyPair['private_key']]);
        } else {
            Db::name('system_settings')->insert(['setting_key' => 'platform_rsa_private_key', 'setting_value' => $keyPair['private_key']]);
        }

        $publicExists = Db::name('system_settings')->where('setting_key', 'platform_rsa_public_key')->find();
        if ($publicExists) {
            Db::name('system_settings')->where('setting_key', 'platform_rsa_public_key')->update(['setting_value' => $keyPair['public_key']]);
        } else {
            Db::name('system_settings')->insert(['setting_key' => 'platform_rsa_public_key', 'setting_value' => $keyPair['public_key']]);
        }

        return $keyPair;
    }
}
