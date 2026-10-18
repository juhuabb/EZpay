<?php
declare(strict_types=1);

namespace app\controller\api;

use app\service\AuthService;
use app\service\SmsService;
use app\service\EmailService;
use app\model\VerifyCode;
use app\model\SystemSetting;
use think\Request;
use think\Response;
use think\exception\ValidateException;

class AuthController
{
    protected AuthService $authService;

    public function __construct(AuthService $authService)
    {
        $this->authService = $authService;
    }

    /**
     * 获取注册模式（前端用，判断开启哪些注册方式）
     */
    public function registerMode(): Response
    {
        $mode = SystemSetting::getValue('register_mode', 'both');
        $smsEnabled = (int)SystemSetting::getValue('sms_enabled', '0');
        $emailEnabled = (int)SystemSetting::getValue('email_enabled', '0');

        return json([
            'code' => 0,
            'msg' => 'success',
            'data' => [
                'mode' => $mode,
                'phone_enabled' => $mode === 'phone' || ($mode === 'both' && $smsEnabled),
                'email_enabled' => $mode === 'email' || ($mode === 'both' && $emailEnabled),
            ]
        ]);
    }

    /**
     * 发送短信验证码
     */
    public function sendSmsCode(Request $request): Response
    {
        $phone = $request->post('phone', '');

        if (!preg_match('/^1[3-9]\d{9}$/', $phone)) {
            return json(['code' => 400, 'msg' => '手机号格式不正确', 'data' => null]);
        }

        $smsEnabled = (int)SystemSetting::getValue('sms_enabled', '0');
        if (!$smsEnabled) {
            return json(['code' => 400, 'msg' => '短信注册未开启', 'data' => null]);
        }

        // 频率限制
        $limitErr = VerifyCode::checkRateLimit($phone, $request->ip());
        if ($limitErr) {
            return json(['code' => 429, 'msg' => $limitErr, 'data' => null]);
        }

        $smsService = new SmsService();
        $code = $smsService->generateCode();

        // 保存验证码
        VerifyCode::createCode($phone, $code, 'phone', 'register', $request->ip());

        // 发送短信
        $sent = $smsService->sendVerifyCode($phone, $code);
        if (!$sent) {
            return json(['code' => 500, 'msg' => '验证码发送失败，请稍后重试', 'data' => null]);
        }

        return json(['code' => 0, 'msg' => '验证码已发送', 'data' => null]);
    }

    /**
     * 发送邮件验证码
     */
    public function sendEmailCode(Request $request): Response
    {
        $email = $request->post('email', '');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return json(['code' => 400, 'msg' => '邮箱格式不正确', 'data' => null]);
        }

        $emailEnabled = (int)SystemSetting::getValue('email_enabled', '0');
        if (!$emailEnabled) {
            return json(['code' => 400, 'msg' => '邮箱注册未开启', 'data' => null]);
        }

        // 频率限制
        $limitErr = VerifyCode::checkRateLimit($email, $request->ip());
        if ($limitErr) {
            return json(['code' => 429, 'msg' => $limitErr, 'data' => null]);
        }

        $emailService = new EmailService();
        $code = $emailService->generateCode();

        // 保存验证码
        VerifyCode::createCode($email, $code, 'email', 'register', $request->ip());

        // 发送邮件
        $sent = $emailService->sendVerifyCode($email, $code);
        if (!$sent) {
            return json(['code' => 500, 'msg' => '验证码发送失败，请稍后重试', 'data' => null]);
        }

        return json(['code' => 0, 'msg' => '验证码已发送', 'data' => null]);
    }

    /**
     * 注册（支持手机号/邮箱）
     */
    public function register(Request $request): Response
    {
        $data = $request->post();

        if (empty($data['username']) || empty($data['password'])) {
            return json(['code' => 400, 'msg' => '缺少必填参数', 'data' => null]);
        }

        // 用户名格式
        if (!preg_match('/^[a-zA-Z0-9_]{3,20}$/', $data['username'])) {
            return json(['code' => 400, 'msg' => '用户名格式不正确(3-20位字母数字下划线)', 'data' => null]);
        }

        $registerType = $data['register_type'] ?? 'phone'; // phone 或 email

        if ($registerType === 'email') {
            // 邮箱注册
            if (empty($data['email']) || empty($data['code'])) {
                return json(['code' => 400, 'msg' => '请输入邮箱和验证码', 'data' => null]);
            }
            if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
                return json(['code' => 400, 'msg' => '邮箱格式不正确', 'data' => null]);
            }

            // 验证邮箱验证码
            if (!VerifyCode::verify($data['email'], $data['code'], 'register')) {
                return json(['code' => 400, 'msg' => '验证码错误或已过期', 'data' => null]);
            }

            try {
                $user = $this->authService->registerByEmail(
                    $data['username'],
                    $data['email'],
                    $data['password']
                );

                return json([
                    'code' => 0,
                    'msg' => '注册成功',
                    'data' => [
                        'user_id' => $user->id,
                        'username' => $user->username,
                    ]
                ]);
            } catch (ValidateException $e) {
                return json(['code' => 400, 'msg' => $e->getMessage(), 'data' => null]);
            }
        } else {
            // 手机号注册
            if (empty($data['phone']) || empty($data['code'])) {
                return json(['code' => 400, 'msg' => '请输入手机号和验证码', 'data' => null]);
            }
            if (!preg_match('/^1[3-9]\d{9}$/', $data['phone'])) {
                return json(['code' => 400, 'msg' => '手机号格式不正确', 'data' => null]);
            }

            // 验证短信验证码
            if (!VerifyCode::verify($data['phone'], $data['code'], 'register')) {
                return json(['code' => 400, 'msg' => '验证码错误或已过期', 'data' => null]);
            }

            try {
                $user = $this->authService->register(
                    $data['username'],
                    $data['phone'],
                    $data['password']
                );

                return json([
                    'code' => 0,
                    'msg' => '注册成功',
                    'data' => [
                        'user_id' => $user->id,
                        'username' => $user->username,
                    ]
                ]);
            } catch (ValidateException $e) {
                return json(['code' => 400, 'msg' => $e->getMessage(), 'data' => null]);
            }
        }
    }

    /**
     * 登录
     */
    public function login(Request $request): Response
    {
        $data = $request->post();

        if (empty($data['username']) || empty($data['password'])) {
            return json(['code' => 400, 'msg' => '请输入用户名和密码', 'data' => null]);
        }

        try {
            $result = $this->authService->login(
                $data['username'],
                $data['password'],
                $request->ip(),
                $request->header('User-Agent', '')
            );

            return json([
                'code' => 0,
                'msg' => '登录成功',
                'data' => [
                    'user' => [
    'id' => $result['user']['id'],
    'username' => $result['user']['username'],
    'phone' => $result['user']['phone'],
    'role' => $result['user']['role'],
    'balance' => $result['user']['balance'],
    'real_name_verified' => $result['user']['real_name_verified'],
    'merchant_verified' => $result['user']['merchant_verified'],
],

                    'access_token' => $result['access_token'],
                    'refresh_token' => $result['refresh_token'],
                    'token_type' => $result['token_type'],
                    'expires_in' => $result['expires_in'],
                ]
            ]);
        } catch (ValidateException $e) {
            return json(['code' => 400, 'msg' => $e->getMessage(), 'data' => null]);
        }
    }

    /**
     * 登出
     */
    public function logout(Request $request): Response
    {
        return json(['code' => 0, 'msg' => '登出成功', 'data' => null]);
    }

    /**
     * 刷新Token
     */
    public function refreshToken(Request $request): Response
    {
        $refreshToken = $request->post('refresh_token', '');

        if (empty($refreshToken)) {
            return json(['code' => 400, 'msg' => '缺少刷新令牌', 'data' => null]);
        }

        try {
            $tokens = $this->authService->refreshToken($refreshToken);

            return json([
                'code' => 0,
                'msg' => '刷新成功',
                'data' => [
                    'access_token' => $tokens['access_token'],
                    'refresh_token' => $tokens['refresh_token'],
                    'token_type' => 'Bearer',
                    'expires_in' => config('points.jwt.access_ttl'),
                ]
            ]);
        } catch (\Exception $e) {
            return json(['code' => 401, 'msg' => $e->getMessage(), 'data' => null]);
        }
    }
}
