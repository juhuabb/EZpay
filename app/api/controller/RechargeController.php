<?php
declare(strict_types=1);

namespace app\api\controller;

use app\service\TronService;
use app\service\BscService;
use app\service\BalanceService;
use think\facade\Db;
use think\facade\Log;
use think\Request;
use think\Response;

class RechargeController
{
    /**
     * 获取充值配置(无需登录)
     */
    public function config(Request $request): Response
    {
        $config = $this->getTronConfig();
        $bscConfig = $this->getBscConfig();
        // 读取积分购买选项和充值赠送设置
        $pointsOptions = \think\facade\Db::name('system_settings')
            ->where('setting_key', 'recharge_points_options')
            ->value('setting_value');
        $bonusEnabled = \think\facade\Db::name('system_settings')
            ->where('setting_key', 'recharge_bonus_enabled')
            ->value('setting_value');
        $bonusPercent = \think\facade\Db::name('system_settings')
            ->where('setting_key', 'recharge_bonus_percent')
            ->value('setting_value');
        $optionsArr = [];
        if ($pointsOptions) {
            $parts = explode(',', $pointsOptions);
            foreach ($parts as $p) {
                $v = intval(trim($p));
                if ($v > 0) $optionsArr[] = $v;
            }
        }
        return json([
            'code' => 0,
            'msg' => 'success',
            'data' => [
                'wallet_address' => $config['receive_address'],
                'usdt_to_points' => $config['usdt_to_points'],
                'trx_to_points' => $config['trx_to_points'],
                'min_amount' => $config['min_amount'],
                'max_amount' => $config['max_amount'],
                'expire_minutes' => $config['expire_minutes'],
                'usdt_contract' => TronService::USDT_CONTRACT,
                'rate_source' => $config['rate_source'] ?? 'fixed',
                'points_options' => $optionsArr,
                'recharge_bonus_enabled' => $bonusEnabled ? intval($bonusEnabled) : 0,
                'recharge_bonus_percent' => $bonusPercent ? floatval($bonusPercent) : 0,
                // BSC
                'bsc_wallet_address' => $bscConfig['receive_address'],
                'bsc_usd_to_points' => $bscConfig['bsc_usd_to_points'],
                'bnb_to_points' => $bscConfig['bnb_to_points'],
                'bsc_usdt_contract' => BscService::USDT_BEP20_CONTRACT,
                'bsc_min_amount' => $bscConfig['min_amount'],
                'bsc_max_amount' => $bscConfig['max_amount'],
            ]
        ]);
    }

    /**
     * 创建充值订单
     * 参数: currency(USDT/TRX), amount
     */
    public function create(Request $request): Response
    {
        $userId = $request->uid;
        $currency = strtoupper(trim($request->param('currency', 'USDT')));
        $amount = floatval($request->param('amount', 0));

        if (!in_array($currency, ['USDT', 'TRX', 'BSC-USD', 'BNB'])) {
            return json(['code' => 400, 'msg' => 'invalid currency', 'data' => null]);
        }

        $isBsc = in_array($currency, ['BSC-USD', 'BNB']);

        if ($isBsc) {
            $config = $this->getBscConfig();
        } else {
            $config = $this->getTronConfig();
        }

        if (empty($config['receive_address'])) {
            return json(['code' => 500, 'msg' => 'wallet not configured', 'data' => null]);
        }

        if ($amount < $config['min_amount']) {
            return json(['code' => 400, 'msg' => "min {$config['min_amount']} {$currency}", 'data' => null]);
        }

        if ($amount > $config['max_amount']) {
            return json(['code' => 400, 'msg' => "max {$config['max_amount']} {$currency}", 'data' => null]);
        }

        // 计算积分
        if ($currency === 'USDT' || $currency === 'BSC-USD') {
            $rate = ($currency === 'USDT') ? $config['usdt_to_points'] : $config['bsc_usd_to_points'];
        } else {
            $rate = ($currency === 'TRX') ? $config['trx_to_points'] : $config['bnb_to_points'];
        }
        $points = bcmul((string)$amount, (string)$rate, 2);

        // 检查是否有未完成的订单
        $pendingOrder = Db::name('recharge_orders')
            ->where('user_id', $userId)
            ->where('status', 0)
            ->where('expired_at', '>', date('Y-m-d H:i:s'))
            ->find();

        if ($pendingOrder) {
            // 返回已有订单
            return json([
                'code' => 0,
                'msg' => '您有未完成的充值订单',
                'data' => [
                    'order_no' => $pendingOrder['order_no'],
                    'wallet_address' => $pendingOrder['wallet_address'],
                    'amount' => $pendingOrder['amount'],
                    'currency' => $pendingOrder['currency'],
                    'points' => $pendingOrder['points'],
                    'status' => $pendingOrder['status'],
                    'created_at' => $pendingOrder['created_at'],
                    'expired_at' => $pendingOrder['expired_at'],
                ]
            ]);
        }

        // 检查金额唯一性（相同金额+币种的待支付订单不能同时存在）
        $collision = Db::name('recharge_orders')
            ->where('currency', $currency)
            ->where('amount', $amount)
            ->where('status', 0)
            ->where('expired_at', '>', date('Y-m-d H:i:s'))
            ->find();

        if ($collision) {
            return json(['code' => 409, 'msg' => '支付金额冲突，请重新选择', 'data' => null]);
        }

        // 生成订单号
        $orderNo = $this->generateOrderNo();
        $now = time();
        $expireMinutes = $config['expire_minutes'];
        $createdAt = date('Y-m-d H:i:s', $now);
        $expiredAt = date('Y-m-d H:i:s', $now + $expireMinutes * 60);

        // 创建订单
        $orderId = Db::name('recharge_orders')->insertGetId([
            'order_no' => $orderNo,
            'user_id' => $userId,
            'currency' => $currency,
            'amount' => $amount,
            'points' => $points,
            'rate' => $rate,
            'wallet_address' => $config['receive_address'],
            'status' => 0,
            'created_at' => $createdAt,
            'expired_at' => $expiredAt,
        ]);

        return json([
            'code' => 0,
            'msg' => '订单创建成功',
            'data' => [
                'order_id' => $orderId,
                'order_no' => $orderNo,
                'wallet_address' => $config['receive_address'],
                'amount' => $amount,
                'currency' => $currency,
                'points' => $points,
                'rate' => $rate,
                'status' => 0,
                'created_at' => $createdAt,
                'expired_at' => $expiredAt,
                'expire_minutes' => $expireMinutes,
            ]
        ]);
    }

    /**
     * 检查支付状态(轮询TronGrid API)
     * 参数: order_no
     */
    public function check(Request $request): Response
    {
        $userId = $request->uid;
        $orderNo = trim($request->param('order_no', ''));

        if (empty($orderNo)) {
            return json(['code' => 400, 'msg' => '订单号不能为空', 'data' => null]);
        }

        $order = Db::name('recharge_orders')
            ->where('order_no', $orderNo)
            ->where('user_id', $userId)
            ->find();

        if (!$order) {
            return json(['code' => 404, 'msg' => '订单不存在', 'data' => null]);
        }

        // 已支付
        if ($order['status'] == 1) {
            return json([
                'code' => 0,
                'msg' => '已支付',
                'data' => [
                    'status' => 'paid',
                    'points' => $order['points'],
                    'tx_hash' => $order['tx_hash'],
                    'paid_at' => $order['paid_at'],
                ]
            ]);
        }

        // 已取消
        if ($order['status'] == 3) {
            return json(['code' => 0, 'msg' => '订单已取消', 'data' => ['status' => 'cancelled']]);
        }

        // 已过期
        if ($order['status'] == 2 || strtotime($order['expired_at']) < time()) {
            // 更新过期状态
            if ($order['status'] == 0) {
                Db::name('recharge_orders')->where('id', $order['id'])->update(['status' => 2]);
            }
            return json(['code' => 0, 'msg' => '订单已过期', 'data' => ['status' => 'expired']]);
        }

        // 待支付：查询链上转账记录
        $matched = $this->checkOnChainPayment($order);

        if ($matched) {
            return json([
                'code' => 0,
                'msg' => '支付成功',
                'data' => [
                    'status' => 'paid',
                    'points' => $order['points'],
                    'tx_hash' => $matched['tx_hash'],
                    'paid_at' => $matched['paid_at'],
                ]
            ]);
        }

        // 仍在等待
        return json([
            'code' => 0,
            'msg' => '等待支付',
            'data' => [
                'status' => 'pending',
                'expired_at' => $order['expired_at'],
            ]
        ]);
    }

    /**
     * 取消订单
     * 参数: order_no
     */
    public function cancel(Request $request): Response
    {
        $userId = $request->uid;
        $orderNo = trim($request->param('order_no', ''));

        $order = Db::name('recharge_orders')
            ->where('order_no', $orderNo)
            ->where('user_id', $userId)
            ->where('status', 0)
            ->find();

        if (!$order) {
            return json(['code' => 400, 'msg' => '订单不存在或已处理', 'data' => null]);
        }

        Db::name('recharge_orders')->where('id', $order['id'])->update(['status' => 3]);

        return json(['code' => 0, 'msg' => '订单已取消', 'data' => null]);
    }

    /**
     * 充值记录
     */
    public function history(Request $request): Response
    {
        $userId = $request->uid;
        $page = (int)$request->get('page', 1);
        $pageSize = (int)$request->get('page_size', 10);

        $list = Db::name('recharge_orders')
            ->where('user_id', $userId)
            ->order('id', 'desc')
            ->page($page, $pageSize)
            ->select();

        $total = Db::name('recharge_orders')
            ->where('user_id', $userId)
            ->count();

        $result = [];
        foreach ($list as $item) {
            $statusText = ['待支付', '已支付', '已过期', '已取消'];
            $result[] = [
                'order_no' => $item['order_no'],
                'currency' => $item['currency'],
                'amount' => $item['amount'],
                'points' => $item['points'],
                'status' => $item['status'],
                'status_text' => $statusText[$item['status']] ?? '未知',
                'tx_hash' => $item['tx_hash'],
                'created_at' => $item['created_at'],
                'paid_at' => $item['paid_at'],
            ];
        }

        return json([
            'code' => 0,
            'msg' => 'success',
            'data' => ['list' => $result, 'total' => $total]
        ]);
    }

    /**
     * 查询链上支付(核心匹配逻辑)
     */
    private function checkOnChainPayment(array $order): ?array
    {
        $walletAddress = $order['wallet_address'];
        $currency = $order['currency'];
        $orderAmount = (string)$order['amount'];
        $isBsc = in_array($currency, ['BSC-USD', 'BNB']);

        // TRON uses milliseconds, BSC uses seconds
        if ($isBsc) {
            $sinceTs = strtotime($order['created_at']) - 60;
        } else {
            $sinceTs = (strtotime($order['created_at']) - 60) * 1000;
        }

        try {
            if ($currency === 'USDT') {
                $tronService = new TronService();
                $transfers = $tronService->getIncomingTrc20Transfers($walletAddress, $sinceTs);
            } elseif ($currency === 'TRX') {
                $tronService = new TronService();
                $transfers = $tronService->getIncomingTrxTransfers($walletAddress, $sinceTs);
            } elseif ($currency === 'BSC-USD') {
                $bscService = new BscService();
                $transfers = $bscService->getIncomingBep20Transfers($walletAddress, $sinceTs);
            } elseif ($currency === 'BNB') {
                $bscService = new BscService();
                $transfers = $bscService->getIncomingBnbTransfers($walletAddress, $sinceTs);
            } else {
                return null;
            }
        } catch (\Exception $e) {
            Log::error('Query on-chain transfer failed: ' . $e->getMessage());
            return null;
        }

        if (empty($transfers)) {
            return null;
        }

        // 遍历转账记录，匹配金额
        foreach ($transfers as $tx) {
            $txAmount = (string)$tx['amount'];
            $txHash = $tx['tx_hash'];

            if (empty($txHash)) {
                continue;
            }

            // 检查tx_hash是否已被其他订单使用(防重复)
            $usedOrder = Db::name('recharge_orders')
                ->where('tx_hash', $txHash)
                ->where('status', 1)
                ->find();

            if ($usedOrder) {
                continue;
            }

            // 金额匹配(允许0.000001的精度误差)
            if (bccomp($txAmount, $orderAmount, 6) === 0) {
                // 匹配成功！使用事务+行锁确保原子性
                try {
                    $matched = Db::transaction(function () use ($order, $tx, $txHash) {
                        // 行锁锁定当前订单
                        $lockedOrder = Db::name('recharge_orders')
                            ->where('id', $order['id'])
                            ->where('status', 0)
                            ->lock(true)
                            ->find();

                        if (!$lockedOrder) {
                            return null;
                        }

                        // 再次检查tx_hash是否被抢
                        $alreadyUsed = Db::name('recharge_orders')
                            ->where('tx_hash', $txHash)
                            ->where('status', 1)
                            ->lock(true)
                            ->find();

                        if ($alreadyUsed) {
                            return null;
                        }

                        $now = date('Y-m-d H:i:s');

                        // 更新订单状态
                        Db::name('recharge_orders')->where('id', $order['id'])->update([
                            'status' => 1,
                            'tx_hash' => $txHash,
                            'from_address' => $tx['from'],
                            'paid_at' => $now,
                        ]);

                        // 给用户加积分(使用balance类型，仅加总余额)
                        $balanceService = new BalanceService();
                        $balanceService->increase($order['user_id'], (float)$order['points'], 'balance');

                        // 记录余额日志
                        $chainLabel = in_array($order['currency'], ['BSC-USD', 'BNB']) ? 'BSC' : 'TRC20';
                        Db::name('balance_logs')->insert([
                            'user_id' => $order['user_id'],
                            'type' => 'recharge',
                            'amount' => $order['points'],
                            'description' => "{$chainLabel} recharge {$order['amount']} {$order['currency']} => {$order['points']} points",
                            'created_at' => $now,
                        ]);

                        // 充值赠送
                        $bonusEnabled = \think\facade\Db::name('system_settings')
                            ->where('setting_key', 'recharge_bonus_enabled')
                            ->value('setting_value');
                        if ($bonusEnabled && intval($bonusEnabled) === 1) {
                            $bonusPercent = \think\facade\Db::name('system_settings')
                                ->where('setting_key', 'recharge_bonus_percent')
                                ->value('setting_value');
                            $bonusPercent = $bonusPercent ? floatval($bonusPercent) : 0;
                            if ($bonusPercent > 0) {
                                $bonusPoints = bcmul((string)$order['points'], (string)bcdiv((string)$bonusPercent, '100', 4), 2);
                                if (floatval($bonusPoints) > 0) {
                                    $balanceService->increase($order['user_id'], (float)$bonusPoints, 'balance');
                                    Db::name('balance_logs')->insert([
                                        'user_id' => $order['user_id'],
                                        'type' => 'recharge_bonus',
                                        'amount' => $bonusPoints,
                                        'description' => "充值赠送 {$bonusPercent}% 到账 {$bonusPoints} 积分",
                                        'created_at' => $now,
                                    ]);
                                }
                            }
                        }

                        // 站内信：通知用户充值到账
                        Db::name('messages')->insert([
                            'user_id'    => $order['user_id'],
                            'title'      => '充值到账通知',
                            'content'    => "您的快捷购买 {$order['points']} 积分已经到账，请查收！",
                            'type'       => 'recharge',
                            'related_id' => $order['id'],
                            'is_read'    => 0,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);

                        return ['tx_hash' => $txHash, 'paid_at' => $now];
                    });

                    return $matched;
                } catch (\Exception $e) {
                    Log::error('充值到账失败: ' . $e->getMessage());
                    return null;
                }
            }
        }

        return null;
    }

    /**
     * 获取TRON配置
     * 兼容.env中变量可能被放在[SECTION]下的情况
     */
    private function getTronConfig(): array
    {
        $rateMode = $this->getEnvFlexible('RATE_MODE', 'fixed');
        $rateMultiplier = floatval($this->getEnvFlexible('RATE_MULTIPLIER', '1'));

        $usdtToPoints = floatval($this->getEnvFlexible('USDT_TO_POINTS_RATE', '7'));
        $trxToPoints = floatval($this->getEnvFlexible('TRX_TO_POINTS_RATE', '2'));
        $rateSource = 'fixed';

        // 实时汇率模式: 从API获取USDT和TRX的CNY价格
        if ($rateMode === 'realtime') {
            try {
                $tronService = new TronService();
                $prices = $tronService->getRealtimePrices();
                if ($prices) {
                    $usdtToPoints = round($prices['usdt_cny'] * $rateMultiplier, 2);
                    $trxToPoints = round($prices['trx_cny'] * $rateMultiplier, 4);
                    $rateSource = $prices['source'] ?? 'realtime';
                }
            } catch (\Exception $e) {
                Log::error('获取实时汇率失败: ' . $e->getMessage());
            }
        }

        return [
            'receive_address' => $this->getEnvFlexible('TRON_RECEIVE_ADDRESS', ''),
            'api_key' => $this->getEnvFlexible('TRON_API_KEY', ''),
            'usdt_to_points' => $usdtToPoints,
            'trx_to_points' => $trxToPoints,
            'min_amount' => floatval($this->getEnvFlexible('TRON_MIN_AMOUNT', '1')),
            'max_amount' => floatval($this->getEnvFlexible('TRON_MAX_AMOUNT', '10000')),
            'expire_minutes' => intval($this->getEnvFlexible('TRON_EXPIRE_MINUTES', '30')),
            'rate_source' => $rateSource,
        ];
    }

    /**
     * Get BSC config
     */
    private function getBscConfig(): array
    {
        $rateMode = $this->getEnvFlexible('RATE_MODE', 'fixed');
        $rateMultiplier = floatval($this->getEnvFlexible('RATE_MULTIPLIER', '1'));

        $bscUsdToPoints = floatval($this->getEnvFlexible('BSC_USD_TO_POINTS_RATE', '7'));
        $bnbToPoints = floatval($this->getEnvFlexible('BNB_TO_POINTS_RATE', '85'));
        $rateSource = 'fixed';

        if ($rateMode === 'realtime') {
            try {
                $bscService = new BscService();
                $prices = $bscService->getRealtimePrices();
                if ($prices) {
                    $bscUsdToPoints = round($prices['usdt_cny'] * $rateMultiplier, 2);
                    $bnbToPoints = round($prices['bnb_cny'] * $rateMultiplier, 4);
                    $rateSource = ($prices['source'] ?? 'realtime') . '+BSC';
                }
            } catch (\Exception $e) {
                Log::error('Failed to get BSC realtime price: ' . $e->getMessage());
            }
        }

        return [
            'receive_address' => $this->getEnvFlexible('BSC_RECEIVE_ADDRESS', ''),
            'rpc_url' => $this->getEnvFlexible('BSC_RPC_URL', ''),
            'bsc_usd_to_points' => $bscUsdToPoints,
            'bnb_to_points' => $bnbToPoints,
            'min_amount' => floatval($this->getEnvFlexible('BSC_MIN_AMOUNT', '1')),
            'max_amount' => floatval($this->getEnvFlexible('BSC_MAX_AMOUNT', '10000')),
            'expire_minutes' => intval($this->getEnvFlexible('BSC_EXPIRE_MINUTES', '30')),
            'rate_source' => $rateSource,
        ];
    }

    /**
     * 灵活读取env变量(兼容section前缀)
     * 先直接读，读不到则在所有env数据中搜索包含该key的项
     */
    private function getEnvFlexible(string $key, $default = '')
    {
        // 1. 直接读取
        $value = env($key, '');
        if (!empty($value)) {
            return $value;
        }

        // 2. 从config读取(config/points.php中也定义了env调用)
        $configVal = config('points.tron.' . strtolower(str_replace('TRON_', '', $key)), '');
        if (!empty($configVal)) {
            return $configVal;
        }

        // 3. 在所有env数据中搜索(处理section前缀问题)
        try {
            $allEnv = \think\facade\Env::get();
            if (is_array($allEnv)) {
                foreach ($allEnv as $envKey => $envValue) {
                    // 匹配: SECTION.KEY 或直接KEY
                    if (stripos($envKey, $key) !== false && !empty($envValue) && is_string($envValue)) {
                        return $envValue;
                    }
                }
            }
        } catch (\Exception $e) {
            // 忽略错误
        }

        return $default;
    }

    /**
     * 生成订单号(18位)
     */
    private function generateOrderNo(): string
    {
        return 'RC' . date('YmdHis') . str_pad((string)mt_rand(0, 99999), 5, '0', STR_PAD_LEFT);
    }
}
