<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Config;

class RsaService
{
    /**
     * 生成RSA密钥对
     */
    public function generateKeyPair(): array
    {
        $bits = Config::get('points.rsa.key_bits', 2048);

        $config = [
            'private_key_bits' => $bits,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ];

        $resource = openssl_pkey_new($config);
        if ($resource === false) {
            throw new \RuntimeException('RSA密钥生成失败: ' . openssl_error_string());
        }

        openssl_pkey_export($resource, $privateKey);
        $publicKeyDetails = openssl_pkey_get_details($resource);

        return [
            'private_key' => $privateKey,
            'public_key' => $publicKeyDetails['key'],
        ];
    }

    /**
     * RSA签名
     */
    public function sign(string $data, string $privateKey): string
    {
        $key = openssl_pkey_get_private($privateKey);
        if ($key === false) {
            throw new \RuntimeException('无效的RSA私钥');
        }

        $algo = Config::get('points.rsa.digest_algo', 'sha256');
        $signature = '';

        $result = openssl_sign($data, $signature, $key, $algo);
        if (!$result) {
            throw new \RuntimeException('RSA签名失败: ' . openssl_error_string());
        }

        return base64_encode($signature);
    }

    /**
     * RSA验签
     */
    public function verify(string $data, string $signature, string $publicKey): bool
    {
        $key = openssl_pkey_get_public($publicKey);
        if ($key === false) {
            throw new \RuntimeException('无效的RSA公钥');
        }

        $algo = Config::get('points.rsa.digest_algo', 'sha256');
        $decodedSignature = base64_decode($signature);

        if ($decodedSignature === false) {
            return false;
        }

        $result = openssl_verify($data, $decodedSignature, $key, $algo);

        return $result === 1;
    }

    /**
     * RSA公钥加密
     */
    public function publicEncrypt(string $data, string $publicKey): string
    {
        $key = openssl_pkey_get_public($publicKey);
        if ($key === false) {
            throw new \RuntimeException('无效的RSA公钥');
        }

        $encrypted = '';
        $result = openssl_public_encrypt($data, $encrypted, $key, OPENSSL_PKCS1_OAEP_PADDING);

        if (!$result) {
            throw new \RuntimeException('RSA加密失败');
        }

        return base64_encode($encrypted);
    }

    /**
     * RSA私钥解密
     */
    public function privateDecrypt(string $encryptedData, string $privateKey): string
    {
        $key = openssl_pkey_get_private($privateKey);
        if ($key === false) {
            throw new \RuntimeException('无效的RSA私钥');
        }

        $decrypted = '';
        $data = base64_decode($encryptedData);
        $result = openssl_private_decrypt($data, $decrypted, $key, OPENSSL_PKCS1_OAEP_PADDING);

        if (!$result) {
            throw new \RuntimeException('RSA解密失败');
        }

        return $decrypted;
    }
}
