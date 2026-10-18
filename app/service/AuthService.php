<?php
declare(strict_types=1);

namespace app\service;

use app\model\User;
use app\model\LoginLog;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use think\facade\Config;
use think\exception\ValidateException;

class AuthService
{
    /**
     * 手机号注册
     */
    public function register(string $username, string $phone, string $password, string $referralCode = ''): User
    {
        // 检查用户名
        if (User::where('username', $username)->find()) {
            throw new ValidateException('用户名已存在');
        }

        // 检查手机号
        if (User::where('phone', $phone)->find()) {
            throw new ValidateException('手机号已被注册');
        }

        // 密码强度
        $minLen = Config::get('points.password.min_length', 6);
        if (strlen($password) < $minLen) {
            throw new ValidateException("密码长度不能少于{$minLen}位");
        }

        // 验证推荐码
        $referredBy = null;
        if (!empty($referralCode)) {
            $referrer = User::where('referral_code', $referralCode)->find();
            if (!$referrer) {
                throw new ValidateException('推荐码不存在');
            }
            $referredBy = $referrer->id;
        }

        $salt = bin2hex(random_bytes(16));
        $passwordHash = password_hash($password, PASSWORD_BCRYPT);

        $user = User::create([
            'username' => $username,
            'phone' => $phone,
            'password_hash' => $passwordHash,
            'salt' => $salt,
            'balance' => 0,
            'principal' => 0,
            'bonus' => 0,
            'role' => 'user',
            'status' => 1,
            'referral_code' => User::generateReferralCode(),
            'referred_by' => $referredBy,
        ]);

        // 生成钱包地址
        $user->wallet_address = User::generateWalletAddress($user->id, $phone, '');
        $user->save();

        return $user;
    }

    /**
     * 邮箱注册
     */
    public function registerByEmail(string $username, string $email, string $password, string $referralCode = ''): User
    {
        // 检查用户名
        if (User::where('username', $username)->find()) {
            throw new ValidateException('用户名已存在');
        }

        // 检查邮箱
        if (User::where('email', $email)->find()) {
            throw new ValidateException('邮箱已被注册');
        }

        // 密码强度
        $minLen = Config::get('points.password.min_length', 6);
        if (strlen($password) < $minLen) {
            throw new ValidateException("密码长度不能少于{$minLen}位");
        }

        // 验证推荐码
        $referredBy = null;
        if (!empty($referralCode)) {
            $referrer = User::where('referral_code', $referralCode)->find();
            if (!$referrer) {
                throw new ValidateException('推荐码不存在');
            }
            $referredBy = $referrer->id;
        }

        $salt = bin2hex(random_bytes(16));
        $passwordHash = password_hash($password, PASSWORD_BCRYPT);

        $user = User::create([
            'username' => $username,
            'email' => $email,
            'password_hash' => $passwordHash,
            'salt' => $salt,
            'balance' => 0,
            'principal' => 0,
            'bonus' => 0,
            'role' => 'user',
            'status' => 1,
            'referral_code' => User::generateReferralCode(),
            'referred_by' => $referredBy,
        ]);

        // 生成钱包地址
        $user->wallet_address = User::generateWalletAddress($user->id, '', $email);
        $user->save();

        return $user;
    }

    /**
     * 登录（支持用户名/手机号/邮箱）
     */
    public function login(string $username, string $password, string $ip, string $userAgent): array
    {
        $user = User::where(function($query) use ($username) {
                $query->where('username', $username)
                      ->whereOr('phone', $username)
                      ->whereOr('email', $username);
            })
            ->find();

        if (!$user) {
            throw new ValidateException('用户不存在');
        }

       if ((int)$user->status !== 1) {
    throw new ValidateException('账户已被锁定');
}

        if (!$user->verifyPassword($password)) {
            LoginLog::record($user->id, $ip, $userAgent, 'password', 0);
            throw new ValidateException('密码错误');
        }

        LoginLog::record($user->id, $ip, $userAgent, 'password', 1);

        $tokens = $this->generateTokens($user);

        return [
            'user' => $user,
            'access_token' => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'],
            'token_type' => 'Bearer',
            'expires_in' => Config::get('points.jwt.access_ttl'),
        ];
    }

    /**
     * 生成JWT Token对
     */
    public function generateTokens(User $user): array
    {
        $config = Config::get('points.jwt');
        $now = time();

        $accessPayload = [
            'iss' => $config['issuer'],
            'aud' => $config['audience'],
            'iat' => $now,
            'exp' => $now + $config['access_ttl'],
            'sub' => $user->id,
            'type' => 'access',
            'role' => $user->role,
        ];

        $refreshPayload = [
            'iss' => $config['issuer'],
            'aud' => $config['audience'],
            'iat' => $now,
            'exp' => $now + $config['refresh_ttl'],
            'sub' => $user->id,
            'type' => 'refresh',
        ];

        return [
            'access_token' => JWT::encode($accessPayload, $config['secret'], $config['algorithm']),
            'refresh_token' => JWT::encode($refreshPayload, $config['secret'], $config['algorithm']),
        ];
    }

    /**
     * 验证并解析JWT Token
     */
    public function parseToken(string $token): array
    {
        $config = Config::get('points.jwt');

        try {
            $decoded = JWT::decode($token, new Key($config['secret'], $config['algorithm']));
            return (array) $decoded;
        } catch (\Exception $e) {
            throw new ValidateException('Token无效或已过期');
        }
    }

    /**
     * 刷新Token
     */
    public function refreshToken(string $refreshToken): array
    {
        $payload = $this->parseToken($refreshToken);

        if (($payload['type'] ?? '') !== 'refresh') {
            throw new ValidateException('无效的刷新令牌');
        }

        $user = User::find($payload['sub']);
        if (!$user || (int)$user->status !== 1) {
            throw new ValidateException('用户不存在或已被禁用');
        }

        return $this->generateTokens($user);
    }
}
