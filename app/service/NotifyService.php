<?php
declare(strict_types=1);

namespace app\service;

use app\model\Transaction;
use app\model\Merchant;
use think\facade\Log;

class NotifyService
{
    protected RsaService $rsaService;

    public function __construct(RsaService $rsaService)
    {
        $this->rsaService = $rsaService;
    }

    /**
     * 通知商户交易结果
     */
    public function notifyTransaction(Transaction $transaction): void
    {
        $merchant = Merchant::find($transaction->merchant_id);
        if (!$merchant || !$merchant->payment_enabled) {
            return;
        }

        // 构建通知数据
        $data = [
            'transaction_id' => $transaction->id,
            'order_id' => $transaction->order_id,
            'buyer_id' => $transaction->buyer_id,
            'points_amount' => $transaction->points_amount,
            'total_price' => $transaction->total_price,
            'status' => $transaction->status,
            'completed_at' => $transaction->completed_at,
            'timestamp' => time(),
        ];

        // 使用商户API密钥签名
        if ($merchant->rsa_private_key) {
            try {
                $data['sign'] = $this->rsaService->sign(
                    json_encode($data, JSON_UNESCAPED_UNICODE),
                    $merchant->rsa_private_key
                );
            } catch (\Exception $e) {
                Log::error('通知签名失败: ' . $e->getMessage());
            }
        }

        // 异步发送通知（实际生产环境应使用队列）
        // 检测 Swoole 运行环境，非 Swoole 环境改为同步发送
        if (function_exists('go') && PHP_SAPI === 'cli' && extension_loaded('swoole')) {
            go(function () use ($merchant, $data) {
                $this->sendNotify($merchant, $data);
            });
        } else {
            // 非 Swoole 环境，同步发送（注册 shutdown 函数避免阻塞主流程）
            register_shutdown_function(function () use ($merchant, $data) {
                $this->sendNotify($merchant, $data);
            });
        }
    }

    /**
     * 发送通知
     */
    protected function sendNotify(Merchant $merchant, array $data): bool
    {
        $notifyUrl = $merchant->website_url;
        if (empty($notifyUrl)) {
            return false;
        }

        // 确保URL有路径
        $url = rtrim($notifyUrl, '/');
        if (!str_contains($url, '/api/')) {
            $url .= '/api/payment/notify';
        }

        try {
            $client = new \GuzzleHttp\Client([
                'timeout' => 5,
                'verify' => false,
            ]);

            $response = $client->post($url, [
                'json' => $data,
                'headers' => [
                    'X-Api-Key' => $merchant->api_key,
                    'Content-Type' => 'application/json',
                ],
            ]);

            $body = json_decode($response->getBody()->getContents(), true);
            return ($body['code'] ?? -1) === 0;
        } catch (\Exception $e) {
            Log::error('商户通知发送失败: ' . $e->getMessage(), [
                'merchant_id' => $merchant->id,
                'data' => $data,
            ]);
            return false;
        }
    }
}
