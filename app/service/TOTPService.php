<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Config;

/**
 * TOTP 服务 - 兼容 Google Authenticator (RFC 6238)
 */
class TOTPService
{
    /**
     * 生成随机密钥
     */
    public function generateSecret(): string
    {
        $length = Config::get('points.totp.secret_length', 20);
        $secret = random_bytes($length);
        return $this->base32Encode($secret);
    }

    /**
     * 获取当前TOTP验证码
     */
    public function getCode(string $secret, ?int $timestamp = null): string
    {
        $period = Config::get('points.totp.period', 30);
        $digits = Config::get('points.totp.digits', 6);
        $algorithm = Config::get('points.totp.algorithm', 'sha1');

        $timestamp = $timestamp ?? time();
        $counter = (int) floor($timestamp / $period);

        return $this->generateHotp($secret, $counter, $digits, $algorithm);
    }

    /**
     * 验证TOTP验证码
     */
    public function verify(string $secret, string $code): bool
    {
        $window = Config::get('points.totp.window', 1);
        $period = Config::get('points.totp.period', 30);
        $now = time();

        for ($i = -$window; $i <= $window; $i++) {
            $timestamp = $now + ($i * $period);
            $generatedCode = $this->getCode($secret, $timestamp);
            if (hash_equals($generatedCode, $code)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 生成TOTP URI (用于二维码)
     */
    public function getQrUri(string $secret, string $account, ?string $issuer = null): string
    {
        $issuer = $issuer ?? Config::get('points.totp.issuer', 'PointsSystem');
        $label = rawurlencode($issuer) . ':' . rawurlencode($account);
        $params = [
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => strtoupper(Config::get('points.totp.algorithm', 'sha1')),
            'digits' => (string) Config::get('points.totp.digits', 6),
            'period' => (string) Config::get('points.totp.period', 30),
        ];

        return 'otpauth://totp/' . $label . '?' . http_build_query($params);
    }

    /**
     * 生成HOTP
     */
    private function generateHotp(string $secret, int $counter, int $digits, string $algorithm): string
    {
        $secretKey = $this->base32Decode($secret);

        // 将counter转为8字节大端序二进制
        $counterBytes = pack('J', $counter);

        $hash = hash_hmac($algorithm, $counterBytes, $secretKey, true);

        // 动态截断
        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
        $binary = (
            ((ord($hash[$offset]) & 0x7F) << 24) |
            ((ord($hash[$offset + 1]) & 0xFF) << 16) |
            ((ord($hash[$offset + 2]) & 0xFF) << 8) |
            (ord($hash[$offset + 3]) & 0xFF)
        );

        $otp = $binary % pow(10, $digits);
        return str_pad((string) $otp, $digits, '0', STR_PAD_LEFT);
    }

    /**
     * Base32 编码
     */
    private function base32Encode(string $data): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $binary = '';
        foreach (str_split($data) as $char) {
            $binary .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }

        $result = '';
        $chunks = str_split($binary, 5);
        foreach ($chunks as $chunk) {
            $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            $result .= $alphabet[bindec($chunk)];
        }

        return $result;
    }

    /**
     * Base32 解码
     */
    private function base32Decode(string $data): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $data = strtoupper(rtrim($data, '='));

        $binary = '';
        foreach (str_split($data) as $char) {
            $pos = strpos($alphabet, $char);
            if ($pos === false) continue;
            $binary .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }

        $result = '';
        $chunks = str_split($binary, 8);
        foreach ($chunks as $chunk) {
            if (strlen($chunk) === 8) {
                $result .= chr(bindec($chunk));
            }
        }

        return $result;
    }
}
