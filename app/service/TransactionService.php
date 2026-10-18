<?php
declare(strict_types=1);

namespace app\service;

use app\model\Transaction;
use app\model\Order;
use app\model\User;
use think\facade\Db;
use think\facade\Log;
use think\exception\ValidateException;

class TransactionService
{
    protected BalanceService $balanceService;
    protected NotifyService $notifyService;

    public function __construct(BalanceService $balanceService, NotifyService $notifyService)
    {
        $this->balanceService = $balanceService;
        $this->notifyService = $notifyService;
    }

    /**
     * 创建购买交易（新流程：status=1 待付款，不立即转账）
     * OTC模式：买家不扣积分，只扣减订单剩余量
     */
    public function createTransaction(int $buyerId, int $orderId, float $pointsAmount, string $paymentMethod = 'balance'): Transaction
    {
        return Db::transaction(function () use ($buyerId, $orderId, $pointsAmount, $paymentMethod) {
            $order = Order::lock(true)->find($orderId);
            if (!$order || !$order->isPurchasable()) {
                throw new ValidateException('订单不存在或已下架');
            }

            if ($pointsAmount <= 0 || $pointsAmount > $order->remaining_amount) {
                throw new ValidateException('购买数量无效');
            }
            // 不允许拆分购买时，必须购买全部剩余数量
            $allowSplit = (int)($order->allow_split ?? 1);
            if ($allowSplit === 0 && abs($pointsAmount - $order->remaining_amount) > 0.001) {
                throw new ValidateException('该挂单不支持拆分购买，需全部购买');
            }
            $minBuyRow = \think\facade\Db::name('system_settings')->where('setting_key', 'trade_min_buy')->find();
            $minBuy = $minBuyRow ? (float)$minBuyRow['setting_value'] : 0;
            if ($allowSplit !== 0 && $minBuy > 0 && $pointsAmount < $minBuy) {
                throw new ValidateException('最小购买量为 ' . $minBuy . ' 积分');
            }
            // 允许拆分时，购买后剩余量不能低于最小购买量（除非全部买完）
            if ($allowSplit !== 0 && $minBuy > 0) {
                $remainingAfter = $order->remaining_amount - $pointsAmount;
                if ($remainingAfter > 0.001 && $remainingAfter < $minBuy) {
                    throw new ValidateException('购买后剩余数量低于最小购买量(' . $minBuy . ')，请全部购买');
                }
            }

            $buyer = User::find($buyerId);
            if (!$buyer || $buyer->status !== 1) {
                throw new ValidateException('用户状态异常');
            }

            if ($buyer->id === $order->user_id) {
                throw new ValidateException('不能购买自己的挂单');
            }

            $totalPrice = round($pointsAmount * $order->unit_price, 2);

            // 扣减订单剩余量（卖家的积分在挂单时已扣除，这里不重复扣）
            $order->remaining_amount -= $pointsAmount;
            if ($order->remaining_amount <= 0) {
                $order->remaining_amount = 0;
                $order->status = 3; // 已完成
            }
            $order->save();

            // 创建交易记录（status=1 待付款，买家不扣积分）
            $transaction = Transaction::create([
                'order_id' => $orderId,
                'buyer_id' => $buyerId,
                'seller_id' => $order->user_id,
                'merchant_id' => $order->merchant_id,
                'points_amount' => $pointsAmount,
                'total_price' => $totalPrice,
                'payment_method' => $paymentMethod,
                'status' => 1,  // 待付款
                'buyer_deleted' => 0,
                'seller_deleted' => 0,
            ]);

            // 清除订单锁定
            $order->locked_by = 0;
            $order->locked_at = null;
            $order->save();

            // 站内信：通知卖家有新买单（失败不影响交易）
            try {
                Db::name('messages')->insert([
                    'user_id'    => $order->user_id,
                    'title'      => '您有新的买单',
                    'content'    => '买家已下单购买您的积分，请等待买家付款',
                    'type'       => 'trade',
                    'related_id' => $transaction->id,
                    'is_read'    => 0,
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
            } catch (\Exception $e) {
                Log::error('站内信发送失败(createTransaction): ' . $e->getMessage());
            }

            return $transaction;
        });
    }

    /**
     * 买家确认付款（1→2）
     * OTC模式：买家线下付款，系统只改状态不扣积分
     */
    public function confirmPayment(int $userId, int $transactionId): Transaction
    {
        return Db::transaction(function () use ($userId, $transactionId) {
            $transaction = Transaction::lock(true)->find($transactionId);
            if (!$transaction) {
                throw new ValidateException('交易不存在');
            }
            if ($transaction->buyer_id !== $userId) {
                throw new ValidateException('只有买家可以确认付款');
            }
            if ($transaction->status !== 1) {
                throw new ValidateException('当前状态不可确认付款');
            }

            $transaction->status = 2; // 交易中
            $transaction->save();
            
            // 站内信：通知卖家买方已付款
            Db::name('messages')->insert([
                'user_id'    => $transaction->seller_id,
                'title'      => '你有新的卖出订单',
                'content'    => '你有新的卖出订单，买方已付款，请在20分钟内确认收款并放币！',
                'type'       => 'trade',
                'related_id' => $transaction->id,
                'is_read'    => 0,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

            return $transaction;
        });
    }

    /**
     * 卖家确认收款（2→3）
     * OTC模式：只给买家加积分（卖家积分在挂单时已扣）
     */
    public function confirmReceipt(int $userId, int $transactionId): Transaction
    {
        return Db::transaction(function () use ($userId, $transactionId) {
            $transaction = Transaction::lock(true)->find($transactionId);
            if (!$transaction) {
                throw new ValidateException('交易不存在');
            }
            if ($transaction->seller_id !== $userId) {
                throw new ValidateException('只有卖家可以确认收款');
            }
            if ($transaction->status !== 2) {
                throw new ValidateException('当前状态不可确认收款');
            }

            // OTC模式：只给买家加积分，卖家余额不变（挂单时已扣）
            $this->balanceService->increase(
                $transaction->buyer_id,
                $transaction->points_amount,
                'balance'
            );

            $transaction->status = 3; // 已完成
            $transaction->completed_at = date('Y-m-d H:i:s');
            $transaction->save();

            // 站内信：通知买家交易完成，积分已到账
            Db::name('messages')->insert([
                'user_id'    => $transaction->buyer_id,
                'title'      => '交易完成',
                'content'    => '卖家已确认收款，积分已到账！',
                'type'       => 'trade',
                'related_id' => $transaction->id,
                'is_read'    => 0,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

            // 异步通知
            $this->notifyService->notifyTransaction($transaction);

            // 推荐奖励：被推荐人完成首笔买入交易
            $this->processReferralReward($transaction);

            return $transaction;
        });
    }

    /**
     * 推荐奖励处理
     * 被推荐人完成首笔买入交易且金额达标时，推荐人获得奖励
     */
    private function processReferralReward(Transaction $transaction): void
    {
        try {
            // 查询买家是否有推荐人
            $buyer = Db::name('users')->where('id', $transaction->buyer_id)->find();
            if (empty($buyer['referred_by'])) {
                return;
            }

            $referrerId = (int)$buyer['referred_by'];

            // 防重复：检查是否已给过此买家的推荐奖励
            $alreadyRewarded = Db::name('balance_logs')
                ->where('user_id', $referrerId)
                ->where('type', 'referral_reward')
                ->where('description', 'like', '%被推荐人ID:' . $transaction->buyer_id . '%')
                ->find();
            if ($alreadyRewarded) {
                return;
            }

            // 读取后台设置
            $reward = 0;
            $minAmount = 100;
            try {
                $rewardStr = Db::name('system_settings')->where('setting_key', 'referral_reward')->value('setting_value');
                $minStr = Db::name('system_settings')->where('setting_key', 'referral_min_trade_amount')->value('setting_value');
                if ($rewardStr !== null && $rewardStr !== '') {
                    $reward = floatval($rewardStr);
                }
                if ($minStr !== null && $minStr !== '') {
                    $minAmount = floatval($minStr);
                }
            } catch (\Exception $e) {}

            if ($reward <= 0) {
                return; // 未启用推荐奖励
            }

            // 检查交易金额是否达到门槛
            if (floatval($transaction->points_amount) < $minAmount) {
                return;
            }

            // 给推荐人加积分(使用bonus类型)
            $this->balanceService->increase($referrerId, $reward, 'bonus');

            // 记录余额日志
            $now = date('Y-m-d H:i:s');
            Db::name('balance_logs')->insert([
                'user_id' => $referrerId,
                'type' => 'referral_reward',
                'amount' => $reward,
                'description' => '推荐奖励：被推荐人ID:' . $transaction->buyer_id . '完成首笔交易，奖励' . $reward . '积分',
                'created_at' => $now,
            ]);

            // 站内信通知推荐人
            Db::name('messages')->insert([
                'user_id' => $referrerId,
                'title' => '推荐奖励到账',
                'content' => '您推荐的用户完成首笔交易，您获得' . $reward . '积分推荐奖励！',
                'type' => 'system',
                'related_id' => 0,
                'is_read' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (\Exception $e) {
            // 推荐奖励失败不影响交易流程
            Log::error('推荐奖励处理失败: ' . $e->getMessage());
        }
    }

    /**
     * 卖家未收到款（2→7 冻结中）
     * 买家已确认付款，卖家表示未收到款，订单进入争议冻结状态
     * 不恢复订单，等待后台管理员裁决
     */
    public function rejectPayment(int $userId, int $transactionId): Transaction
    {
        return Db::transaction(function () use ($userId, $transactionId) {
            $transaction = Transaction::lock(true)->find($transactionId);
            if (!$transaction) {
                throw new ValidateException('交易不存在');
            }
            if ($transaction->seller_id !== $userId) {
                throw new ValidateException('只有卖家可以操作');
            }
            if ($transaction->status !== 2) {
                throw new ValidateException('当前状态不可操作');
            }

            // 不恢复订单，冻结交易，等待后台裁决
            $transaction->status = 7; // 争议冻结中（status=7，等待管理员仲裁：complete→3 / cancel→6）
            $transaction->save();

            // 站内信：通知买家卖家未收到款，订单已冻结
            $now = date('Y-m-d H:i:s');
            Db::name('messages')->insert([
                'user_id'    => $transaction->buyer_id,
                'title'      => '订单已冻结',
                'content'    => '卖家表示未收到您的付款，订单已临时冻结，系统将在30分钟内做出判断。如有疑问请发起申诉。',
                'type'       => 'trade',
                'related_id' => $transaction->id,
                'is_read'    => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            // 站内信：通知卖家订单已冻结
            Db::name('messages')->insert([
                'user_id'    => $transaction->seller_id,
                'title'      => '订单已冻结',
                'content'    => '您标记未收到款的订单已临时冻结，系统将在30分钟内做出判断。请耐心等待。',
                'type'       => 'trade',
                'related_id' => $transaction->id,
                'is_read'    => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return $transaction;
        });
    }

    /**
     * 管理员裁决争议订单
     * action=complete: 完成交易，积分给买家
     * action=cancel: 取消挂单，恢复订单上架
     */
    public function resolveDispute(int $transactionId, string $action): Transaction
    {
        return Db::transaction(function () use ($transactionId, $action) {
            $transaction = Transaction::lock(true)->find($transactionId);
            if (!$transaction) {
                throw new ValidateException('交易不存在');
            }
            if ($transaction->status !== 7) {
                throw new ValidateException('该交易不在冻结状态');
            }

            $now = date('Y-m-d H:i:s');

            if ($action === 'complete') {
                // 完成交易：给买家加积分
                $this->balanceService->increase(
                    $transaction->buyer_id,
                    $transaction->points_amount,
                    'balance'
                );

                $transaction->status = 3; // 已完成
                $transaction->completed_at = $now;
                $transaction->dispute_result = 'complete';
                $transaction->save();

                // 通知买家
                Db::name('messages')->insert([
                    'user_id'    => $transaction->buyer_id,
                    'title'      => '交易完成',
                    'content'    => '管理员已确认交易完成，积分已到账！',
                    'type'       => 'trade',
                    'related_id' => $transaction->id,
                    'is_read'    => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                // 通知卖家
                Db::name('messages')->insert([
                    'user_id'    => $transaction->seller_id,
                    'title'      => '交易完成',
                    'content'    => '管理员已确认交易完成，交易积分已付给买家。',
                    'type'       => 'trade',
                    'related_id' => $transaction->id,
                    'is_read'    => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

            } elseif ($action === 'cancel') {
                // 取消挂单：恢复订单上架
                $order = Order::lock(true)->find($transaction->order_id);
                if ($order) {
                    $order->remaining_amount += $transaction->points_amount;
                    if ($order->status == 3) {
                        $order->status = 1; // 恢复上架
                    }
                    $order->save();
                }

                $transaction->status = 6; // 仲裁取消（判卖家胜）：交易取消，订单恢复上架
                $transaction->dispute_result = 'cancel';
                $transaction->save();

                // 通知卖家
                Db::name('messages')->insert([
                    'user_id'    => $transaction->seller_id,
                    'title'      => '订单已恢复上架',
                    'content'    => '管理员确认您未收到款项，订单已恢复上架状态。',
                    'type'       => 'trade',
                    'related_id' => $transaction->id,
                    'is_read'    => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                // 通知买家
                Db::name('messages')->insert([
                    'user_id'    => $transaction->buyer_id,
                    'title'      => '交易已取消',
                    'content'    => '管理员确认卖家未收到款项，交易已取消。',
                    'type'       => 'trade',
                    'related_id' => $transaction->id,
                    'is_read'    => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

            } else {
                throw new ValidateException('无效的操作类型');
            }

            return $transaction;
        });
    }

    /**
     * 取消交易（超时未支付，1→4）
     * OTC模式：恢复订单剩余量，双方余额不变
     */
    public function cancelTransaction(int $transactionId): bool
    {
        return Db::transaction(function () use ($transactionId) {
            $transaction = Transaction::lock(true)->find($transactionId);
            if (!$transaction || $transaction->status !== 1) {
                throw new ValidateException('交易不存在或状态异常');
            }

            // 恢复订单剩余量（使用行锁）
            $order = Order::lock(true)->find($transaction->order_id);
            if ($order && $order->status === 1) {
                $order->remaining_amount += $transaction->points_amount;
                $order->save();
            }

            $transaction->status = 8; // 买方取消：买家在待付款阶段主动取消（订单剩余量已在上面恢复上架）
            $transaction->save();

            // 站内信：通知卖家交易已取消
            Db::name('messages')->insert([
                'user_id'    => $transaction->seller_id,
                'title'      => '交易已取消',
                'content'    => '买家已取消交易，挂单已恢复',
                'type'       => 'trade',
                'related_id' => $transaction->id,
                'is_read'    => 0,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

            return true;
        });
    }

    /**
     * 退款（管理后台用，status=2时退回）
     * OTC模式：只恢复订单剩余量，不做余额双向转账
     */
    public function refundTransaction(int $transactionId): bool
    {
        return Db::transaction(function () use ($transactionId) {
            $transaction = Transaction::lock(true)->find($transactionId);
            if (!$transaction || $transaction->status !== 2) {
                throw new ValidateException('交易不存在或不可退款');
            }

            // OTC模式：不执行余额双向转账，只恢复订单
            $order = Order::lock(true)->find($transaction->order_id);
            if ($order) {
                $order->remaining_amount += $transaction->points_amount;
                if ($order->status === 3) {
                    $order->status = 1;
                }
                $order->save();
            }

            $transaction->save([
                'status' => 4, // 已取消/已退款：后台对 status=2 的交易退款，OTC模式仅恢复订单上架，不做余额转账
            ]);

            $this->notifyService->notifyTransaction($transaction);

            return true;
        });
    }
}
