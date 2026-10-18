<?php
declare(strict_types=1);

namespace app\middleware;

use app\model\Merchant;
use app\service\RsaService;
use think\facade\Cache;
use think\facade\Db;
use think\facade\Log;
use think\Request;
use think\Response;

class MerchantApiAuth
{
    protected RsaService $rsaService;

    public function __construct(RsaService $rsaService)
    {
        $this->rsaService = $rsaService;
    }

    /**
     * 入口：记录请求和响应，再调用认证逻辑
     */
    public function handle(Request $request, \Closure $next): Response
    {
        // 提前捕获请求信息
        $logData = [
            'merchant_id' => 0,
            'merchant_name' => '',
            'api_key' => $request->header('X-Api-Key', ''),
            'signature' => $request->header('X-Signature', ''),
            'nonce' => $request->header('X-Nonce', ''),
            'req_timestamp' => $request->header('X-Timestamp', ''),
            'request_method' => $request->method(),
            'request_path' => '/' . ltrim($request->pathinfo(), '/'),
            'request_url' => $request->url(true),
            'request_data' => $request->getContent() ?: json_encode($request->param()),
            'client_ip' => $request->ip(),
            'user_agent' => $request->header('user-agent', ''),
        ];

        // 执行认证 + 控制器
        $response = $this->authenticate($request, $next, $logData);

        // 记录请求日志
        $this->saveApiLog($logData, $response);

        return $response;
    }

    /**
     * 认证逻辑（原handle方法）
     */
    private function authenticate(Request $request, \Closure $next, array &$logData): Response
    {
        $apiKey = $request->header('X-Api-Key', '');
        $timestamp = $request->header('X-Timestamp', '');
        $signature = $request->header('X-Signature', '');
        $nonce = $request->header('X-Nonce', '');

        if (empty($apiKey) || empty($timestamp)) {
            return json(['code' => 401, 'msg' => '缺少认证参数', 'data' => null], 401);
        }

        // 验证时间戳防重放（5分钟内有效）
        if (abs(time() - (int) $timestamp) > 300) {
            return json(['code' => 401, 'msg' => '请求已过期', 'data' => null], 401);
        }

        // 查找商户
        $merchant = Merchant::where('api_key', $apiKey)
            ->where('status', 1)
            ->where('payment_enabled', 1)
            ->find();

        if (!$merchant) {
            return json(['code' => 401, 'msg' => '无效的API密钥或商户未启用支付', 'data' => null], 401);
        }

        // 记录商户信息到日志
        $logData['merchant_id'] = $merchant->id;
        $logData['merchant_name'] = $merchant->company_name ?: '';

        // 强制要求RSA签名
        if (empty($merchant->rsa_public_key)) {
            return json(['code' => 401, 'msg' => '商户未配置RSA密钥对，请先在商户面板生成RSA密钥', 'data' => null], 401);
        }

        if (empty($signature)) {
            return json(['code' => 401, 'msg' => '缺少签名参数(X-Signature)', 'data' => null], 401);
        }

        // 验证RSA签名
        $dataToVerify = $apiKey . $timestamp . $request->getContent();
        try {
            $valid = $this->rsaService->verify($dataToVerify, $signature, $merchant->rsa_public_key);
        } catch (\Throwable $e) {
            return json(['code' => 401, 'msg' => '签名验证异常: ' . $e->getMessage(), 'data' => null], 401);
        }

        if (!$valid) {
            return json(['code' => 401, 'msg' => '签名验证失败', 'data' => null], 401);
        }

        // nonce防重放
        if (empty($nonce)) {
            return json(['code' => 401, 'msg' => '缺少nonce参数(X-Nonce)', 'data' => null], 401);
        }

        if (strlen($nonce) < 8 || strlen($nonce) > 64) {
            return json(['code' => 401, 'msg' => 'nonce长度不合法', 'data' => null], 401);
        }

        $cacheKey = 'merchant_api_nonce_' . md5($apiKey . $nonce);
        try {
            $cached = Cache::get($cacheKey);
            if ($cached) {
                return json(['code' => 401, 'msg' => '请求已使用，请勿重放', 'data' => null], 401);
            }
            // 缓存5分钟（与时间戳窗口一致）
            Cache::set($cacheKey, 1, 300);
        } catch (\Throwable $e) {
            // 缓存异常不阻塞请求，记录日志继续
            Log::warning('MerchantApiAuth nonce缓存异常: ' . $e->getMessage());
        }

        // 域名验证：商户配置了website_url时强制校验来源域名
        $merchantDomain = $this->extractDomain($merchant->website_url ?: '');
        if (!empty($merchantDomain)) {
            $requestDomain = $this->getRequestDomain($request);
            if (empty($requestDomain)) {
                return json(['code' => 403, 'msg' => '无法验证请求来源，请在商户服务端设置Origin或Referer请求头', 'data' => null], 403);
            }
            if (!$this->domainMatches($requestDomain, $merchantDomain)) {
                return json(['code' => 403, 'msg' => '请求来源域名与商户绑定域名不匹配', 'data' => null], 403);
            }
        }

        // IP白名单验证：商户必须配置server_ip，未配置则拒绝
        $merchantIp = trim($merchant->server_ip ?: '');
        if (empty($merchantIp)) {
            return json(['code' => 403, 'msg' => '商户未配置服务器IP白名单，请先在后台配置', 'data' => null], 403);
        }
        $clientIp = $request->ip();
        $allowedIps = array_filter(array_map('trim', explode(',', $merchantIp)), function($ip) {
            return !empty($ip);
        });
        if (empty($allowedIps) || !in_array($clientIp, $allowedIps)) {
            return json(['code' => 403, 'msg' => '请求来源IP[' . $clientIp . ']与商户绑定IP不匹配', 'data' => null], 403);
        }

        // 注入商户信息
        $request->merchantId = $merchant->id;
        $request->merchantUserId = $merchant->user_id;

        return $next($request);
    }

    /**
     * 记录API请求日志到数据库
     */
    private function saveApiLog(array $logData, Response $response): void
    {
        try {
            Db::table('merchant_api_logs')->insert([
                'merchant_id' => $logData['merchant_id'],
                'merchant_name' => $logData['merchant_name'],
                'api_key' => mb_substr($logData['api_key'], 0, 255),
                'signature' => mb_substr($logData['signature'], 0, 500),
                'nonce' => mb_substr($logData['nonce'], 0, 64),
                'req_timestamp' => mb_substr($logData['req_timestamp'], 0, 20),
                'request_method' => $logData['request_method'],
                'request_path' => mb_substr($logData['request_path'], 0, 255),
                'request_url' => mb_substr($logData['request_url'], 0, 500),
                'request_data' => mb_substr($logData['request_data'], 0, 65535),
                'response_code' => $response->getCode(),
                'response_data' => mb_substr($response->getContent(), 0, 65535),
                'client_ip' => $logData['client_ip'],
                'user_agent' => mb_substr($logData['user_agent'], 0, 255),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            Log::warning('MerchantApiAuth日志记录异常: ' . $e->getMessage());
        }
    }

    /**
     * 从请求头中提取来源域名
     * 优先取Origin，其次取Referer
     */
    private function getRequestDomain(Request $request): string
    {
        $origin = $request->header('Origin', '');
        if (!empty($origin)) {
            return $this->extractDomain($origin);
        }
        $referer = $request->header('Referer', '');
        if (!empty($referer)) {
            return $this->extractDomain($referer);
        }
        return '';
    }

    /**
     * 从URL中提取域名（小写）
     */
    private function extractDomain(string $url): string
    {
        $url = trim($url);
        if (empty($url)) {
            return '';
        }
        // 如果没有协议前缀，补上https://让parse_url正确解析
        if (strpos($url, 'http://') !== 0 && strpos($url, 'https://') !== 0) {
            $url = 'https://' . $url;
        }
        $parsed = parse_url($url);
        $host = $parsed['host'] ?? '';
        return strtolower(trim($host));
    }

    /**
     * 域名匹配检查（根域名自动适配www）
     */
    private function domainMatches(string $requestDomain, string $merchantDomain): bool
    {
        if ($requestDomain === $merchantDomain) {
            return true;
        }
        $reqRoot = preg_replace('/^www\./', '', $requestDomain);
        $merRoot = preg_replace('/^www\./', '', $merchantDomain);
        return $reqRoot === $merRoot;
    }
}
