<?php
declare(strict_types=1);

namespace app\controller\api;

use app\model\Order;
use app\model\Transaction;
use app\service\TransactionService;
use app\service\BalanceService;
use app\service\NotifyService;
use app\service\RsaService;
use think\Request;
use think\Response;
use think\exception\ValidateException;

class MerchantApiController
{
    /**
     * 商户发起支付（外部商户调用）
     */
    public function pay(Request $request): Response
    {
        $data = $request->post();
        $merchantId = $request->merchantId;

        if (empty($data['order_id']) || empty($data['amount'])) {
            return json(['code' => 400, 'msg' => '缺少必要参数', 'data' => null]);
        }

        // 验证订单归属
        $order = Order::where('id', (int) $data['order_id'])
            ->where('merchant_id', $merchantId)
            ->find();

        if (!$order || !$order->isPurchasable()) {
            return json(['code' => 400, 'msg' => '订单不存在或不可购买', 'data' => null]);
        }

        $pointsAmount = min((float) $data['amount'], $order->remaining_amount);
        if ($pointsAmount <= 0) {
            return json(['code' => 400, 'msg' => '购买数量无效', 'data' => null]);
        }

        // 获取买家ID（从参数或session）
        $buyerId = (int) ($data['buyer_id'] ?? 0);
        if ($buyerId <= 0) {
            return json(['code' => 400, 'msg' => '缺少买家ID', 'data' => null]);
        }

        try {
            $balanceService = new BalanceService();
            $rsaService = new RsaService();
            $notifyService = new NotifyService($rsaService);
            $transactionService = new TransactionService($balanceService, $notifyService);

            $transaction = $transactionService->createTransaction(
                $buyerId,
                $order->id,
                $pointsAmount,
                'api'
            );

            $responseData = [
                'transaction_id' => $transaction->id,
                'order_id' => $order->id,
                'points_amount' => $transaction->points_amount,
                'total_price' => $transaction->total_price,
                'status' => $transaction->status,
            ];

            return json(['code' => 0, 'msg' => '支付成功', 'data' => $responseData]);
        } catch (ValidateException $e) {
            return json(['code' => 400, 'msg' => $e->getMessage(), 'data' => null]);
        }
    }

    /**
     * 查询交易状态
     */
    public function query(Request $request): Response
    {
        $merchantId = $request->merchantId;
        $transactionId = (int) $request->post('transaction_id', 0);

        if ($transactionId <= 0) {
            return json(['code' => 400, 'msg' => '缺少交易ID', 'data' => null]);
        }

        $transaction = Transaction::where('id', $transactionId)
            ->where('merchant_id', $merchantId)
            ->find();

        if (!$transaction) {
            return json(['code' => 404, 'msg' => '交易不存在', 'data' => null]);
        }

        return json([
            'code' => 0,
            'msg' => 'success',
            'data' => [
                'transaction_id' => $transaction->id,
                'order_id' => $transaction->order_id,
                'buyer_id' => $transaction->buyer_id,
                'points_amount' => $transaction->points_amount,
                'total_price' => $transaction->total_price,
                'status' => $transaction->status,
                'created_at' => $transaction->created_at,
                'completed_at' => $transaction->completed_at,
            ]
        ]);
    }

    /**
     * 商户发起退款
     */
    public function refund(Request $request): Response
    {
        $merchantId = $request->merchantId;
        $transactionId = (int) $request->post('transaction_id', 0);

        if ($transactionId <= 0) {
            return json(['code' => 400, 'msg' => '缺少交易ID', 'data' => null]);
        }

        $transaction = Transaction::where('id', $transactionId)
            ->where('merchant_id', $merchantId)
            ->where('status', 2)
            ->find();

        if (!$transaction) {
            return json(['code' => 400, 'msg' => '交易不存在或不可退款', 'data' => null]);
        }

        try {
            $balanceService = new BalanceService();
            $rsaService = new RsaService();
            $notifyService = new NotifyService($rsaService);
            $transactionService = new TransactionService($balanceService, $notifyService);

            $transactionService->refundTransaction($transactionId);

            return json(['code' => 0, 'msg' => '退款成功', 'data' => null]);
        } catch (ValidateException $e) {
            return json(['code' => 400, 'msg' => $e->getMessage(), 'data' => null]);
        }
    }
}
