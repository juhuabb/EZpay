<?php
declare(strict_types=1);

namespace app\service;

use app\model\Order;
use app\model\User;
use think\facade\Db;
use think\exception\ValidateException;

class OrderService
{
    /**
     * 安全检测：检测文本是否包含木马/病毒/漏洞利用等
     */
    private function validateTextSecurity(string $text): void
    {
        if ($text === '') return;
        $lower = strtolower($text);
        $patterns = [
            '/eval\s*\(/',
            '/assert\s*\(/',
            '/base64_decode\s*\(/',
            '/gzinflate\s*\(/',
            '/gzuncompress\s*\(/',
            '/str_rot13\s*\(/',
            '/shell_exec\s*\(/',
            '/passthru\s*\(/',
            '/\bsystem\s*\(/',
            '/\bexec\s*\(/',
            '/popen\s*\(/',
            '/proc_open\s*\(/',
            '/pcntl_exec\s*\(/',
            '/preg_replace.*\/e/',
            '/create_function\s*\(/',
            '/call_user_func/',
            '/<\?php/',
            '/<\?=/',
            '/<script/i',
            '/onerror\s*=/i',
            '/onload\s*=/i',
            '/onclick\s*=/i',
            '/javascript:/i',
            '/vbscript:/i',
            '/document\.cookie/i',
            '/document\.write/i',
            '/window\.location/i',
            '/union\s+select/i',
            '/information_schema/i',
            '/into\s+outfile/i',
            '/load_file\s*\(/i',
            '/benchmark\s*\(/i',
            '/0x[0-9a-f]{8,}/i',
            '/chr\s*\(\s*\d+\s*\)/',
            '/pack\s*\(/',
            '/\$_(get|post|request|cookie|server)\s*\[/i',
            '/file_get_contents\s*\(/',
            '/file_put_contents\s*\(/',
            '/\bfopen\s*\(/',
            '/fputs\s*\(/',
            '/fwrite\s*\(/',
            '/move_uploaded_file\s*\(/',
            '/\bcopy\s*\(/',
            '/\brename\s*\(/',
            '/\bchmod\s*\(/',
            '/\bunlink\s*\(/',
            '/`[^`]+`/',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $lower) || preg_match($pattern, $text)) {
                throw new ValidateException('输入内容包含不安全字符');
            }
        }
    }

    /**
     * 创建挂单
     */
    public function createOrder(int $userId, array $data)
    {
        
        // ===== 支付密码验证（新增） =====
$userModel = User::find($userId);
if (!$userModel) {
    throw new ValidateException('用户不存在');
}
if (empty($userModel->login_password) || empty($userModel->pay_salt)) {
    throw new ValidateException('请先设置支付密码');
}
$payPassword = trim($data['pay_password'] ?? '');
if ($payPassword === '') {
    throw new ValidateException('请输入支付密码');
}
if (!$userModel->verifyPayPassword($payPassword)) {
    throw new ValidateException('支付密码错误');
}

        
        
        // ===== 后端输入校验 =====

        // 标题校验：仅限中文/字母/数字，最多8个字符
        $title = trim($data['title'] ?? '');
        if ($title === '') {
            throw new ValidateException('请输入挂单标题');
        }
        if (mb_strlen($title) > 8) {
            throw new ValidateException('标题不能超过8个字符');
        }
        if (!preg_match('/^[\x{4e00}-\x{9fa5}a-zA-Z0-9]+$/u', $title)) {
            throw new ValidateException('标题仅限中文、字母、数字，不能包含任何符号');
        }

        // 数量校验：数字，非负，最多2位小数
        $amountStr = (string)($data['amount'] ?? $data['points_amount'] ?? '');
        $amountStr = trim($amountStr);
        if ($amountStr === '') {
            throw new ValidateException('请输入积分数量');
        }
        if (!preg_match('/^\d+(\.\d{1,2})?$/', $amountStr)) {
            throw new ValidateException('积分数量必须为数字，最多2位小数，不能为负数');
        }

        // 单价校验：数字，非负，最多2位小数
        $priceStr = (string)($data['unit_price'] ?? '');
        $priceStr = trim($priceStr);
        if ($priceStr === '') {
            throw new ValidateException('请输入单价');
        }
        if (!preg_match('/^\d+(\.\d{1,2})?$/', $priceStr)) {
            throw new ValidateException('单价必须为数字，最多2位小数，不能为负数');
        }

        // 备注校验：最多50个字符 + 安全检测
        $remark = trim($data['remark'] ?? '');
        if ($remark !== '') {
            if (mb_strlen($remark) > 50) {
                throw new ValidateException('备注说明最多50个字符');
            }
            $this->validateTextSecurity($remark);
        }

        // ===== 以下为原有业务逻辑 =====

        // 1. 检查实名认证（统一以 users.real_name_verified=1 为准）
        //    说明：系统存在两套实名数据——新版填姓名+身份证号（users.real_name_verified），
        //    旧版上传身份证正反面照片（user_verify 表）。三条通过路径（新版后台审核、
        //    旧版照片审核 reviewVerify、后台直接设置）最终都会把 users.real_name_verified 置 1。
        //    因此这里以该字段为权威判定，兼容旧版 user_verify 审核通过的历史数据作为兜底，
        //    避免出现“首页/个人中心显示已实名，挂单却提示未实名”的不一致。
        $realNameVerified = (int) Db::name('users')->where('id', $userId)->value('real_name_verified');
        $legacyVerified = Db::name('user_verify')
            ->where('user_id', $userId)
            ->where('status', 1)
            ->find();
        if ($realNameVerified !== 1 && !$legacyVerified) {
            throw new ValidateException('需要先完成实名认证才能挂单');
        }

                // 2. 检查是否配置了收款方式（merchant_id 实际存储 user_id）
        $paymentMethods = Db::name('payment_methods')
            ->where('merchant_id', $userId)
            ->select()
            ->toArray();
        if (empty($paymentMethods)) {
            throw new ValidateException('需要先配置收款方式才能挂单');
        }

        //2-2 校验前端传来的收款方式ID
        $paymentMethodId = (int)($data['payment_method_id'] ?? 0);
        $validMethod = null;
        foreach ($paymentMethods as $pm) {
            if ($pm['id'] == $paymentMethodId) {
                $validMethod = $pm;
                break;
            }
        }
        if (!$validMethod) {
            throw new ValidateException('请选择有效的收款方式');
        }


        // 3. 获取提交参数
        $pointsAmount = $data['amount'] ?? $data['points_amount'] ?? 0;

        $unitPrice = (float)($data['unit_price'] ?? 0);

                // 4. 积分精确到小数点后2位
        $pointsAmount = round((float)$pointsAmount, 2);
        if ($pointsAmount <= 0) {
            throw new ValidateException('积分数量必须大于0');
        }


        if ($unitPrice <= 0) {
            throw new ValidateException('单价必须大于0');
        }

        // 5. 读取交易配置（手续费率、最小/最大挂单量）
        $settings = [];
        $settingRows = Db::name('system_settings')
            ->whereIn('setting_key', ['trade_fee_rate', 'trade_min_amount', 'trade_max_amount'])
            ->select();
        foreach ($settingRows as $row) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }

        $feeRate = (float)($settings['trade_fee_rate'] ?? 2) / 100;
        $minAmount = (int)($settings['trade_min_amount'] ?? 100);
        $maxAmount = (int)($settings['trade_max_amount'] ?? 1000000);

        // 6. 挂单量范围检查
        if ($pointsAmount < $minAmount) {
            throw new ValidateException("最低挂单量为 {$minAmount} 积分");
        }
        if ($pointsAmount > $maxAmount) {
            throw new ValidateException("最高挂单量为 {$maxAmount} 积分");
        }

        // 7. 计算手续费和总扣除
       $feeAmount = round($pointsAmount * $feeRate, 2);

       $totalDeduction = round($pointsAmount + $feeAmount, 2);


        // 8. 检查余额
        $user = Db::name('users')->where('id', $userId)->find();
        if (!$user) {
            throw new ValidateException('用户不存在');
        }

        $balance = (float)$user['balance'];
        if ($balance < $totalDeduction) {
            throw new ValidateException("余额不足，需要 {$totalDeduction} 积分（含手续费 {$feeAmount}），当前余额 {$balance}");
        }

        // 8-1. 拆分购买选项
        $allowSplit = (int)($data['allow_split'] ?? 1);
        if ($allowSplit !== 0 && $allowSplit !== 1) {
            $allowSplit = 1;
        }

        // 9. 冻结余额 + 创建订单（同一事务，确保原子性）
        $totalAmount = round($pointsAmount * $unitPrice, 2);

        // 10-1 生成订单号: 随机取10位md5字符 + 8位随机大写字母 = 18位
        $md5Full = md5($userId . '.' . time());
        $orderNoPart1 = '';
        $md5Chars = str_split($md5Full);
        for ($i = 0; $i < 10; $i++) {
            $orderNoPart1 .= $md5Chars[mt_rand(0, 31)];
        }
        $orderNoPart2 = '';
        for ($i = 0; $i < 8; $i++) {
            $orderNoPart2 .= chr(mt_rand(65, 90));
        }
        $orderNo = $orderNoPart1 . $orderNoPart2;

        try {
                        $orderId = Db::transaction(function () use ($userId, $totalDeduction, $orderNo, $paymentMethodId, $data, $pointsAmount, $feeAmount, $feeRate, $unitPrice, $totalAmount, $allowSplit) {
                // 冻结余额（扣除积分）— 使用行锁
                $userRow = Db::name('users')->where('id', $userId)->lock(true)->find();
                if ((float)$userRow['balance'] < $totalDeduction) {
                    throw new ValidateException("余额不足");
                }
                Db::name('users')
                    ->where('id', $userId)
                    ->dec('balance', $totalDeduction)
                    ->update();

                // 创建订单
                $orderId = Db::name('orders')->insertGetId([
                    'order_no'         => $orderNo,
                    'merchant_id'      => $userId,
                    'user_id'          => $userId,
                    'payment_method_id' => $paymentMethodId,
                    'title'            => $data['title'] ?? '积分出售',
                    'remark' => $data['remark'] ?? '',
                    'points_amount'    => $pointsAmount,
                    'fee_amount'       => $feeAmount,
                    'fee_rate'         => $feeRate,
                    'unit_price'       => $unitPrice,
                    'total_amount'     => $totalAmount,
                    'remaining_amount' => $pointsAmount,
                    'allow_split'      => $allowSplit,
                    'status'           => 1,
                    'locked_by'        => 0,
                    'created_at'       => date('Y-m-d H:i:s'),
                    'updated_at'       => date('Y-m-d H:i:s'),
                ]);

                return $orderId;
            });

            return [
                'order_id'       => $orderId,
                'points_amount'  => $pointsAmount,
                'fee_amount'     => $feeAmount,
                'fee_rate'       => $feeRate,
                'total_deduction'=> $totalDeduction,
                'unit_price'     => $unitPrice,
                'total_amount'   => $totalAmount,
            ];
        } catch (ValidateException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new ValidateException('创建订单失败：' . $e->getMessage());
        }
    }

    /**
     * 更新挂单
     */
    public function updateOrder(int $orderId, int $userId, array $data)
    {
        $order = Db::name('orders')
            ->where('id', $orderId)
            ->where('user_id', $userId)
            ->find();
        if (!$order) {
            throw new ValidateException('订单不存在');
        }

        if ($order['status'] !== 1 && $order['status'] !== 2) {
            throw new ValidateException('当前状态不可修改');
        }

        $updateData = [];
        if (isset($data['title'])) {
            $updateData['title'] = $data['title'];
        }
        if (isset($data['unit_price'])) {
            $newPrice = (float)$data['unit_price'];
            if ($newPrice <= 0) {
                throw new ValidateException('单价必须大于0');
            }
            $updateData['unit_price'] = $newPrice;
            $updateData['total_amount'] = round($order['remaining_amount'] * $newPrice, 2);
        }
        if (isset($data['status'])) {
            $status = (int)$data['status'];
            if (!in_array($status, [1, 2, 4])) {
                throw new ValidateException('无效的状态值');
            }
            $updateData['status'] = $status;
        }

        if (!empty($updateData)) {
            $updateData['updated_at'] = date('Y-m-d H:i:s');
            Db::name('orders')->where('id', $orderId)->update($updateData);
        }

        return Db::name('orders')->where('id', $orderId)->find();
    }

    /**
     * 取消(下架)挂单
     * 1. 检查是否有正在进行的交易（买家待支付），有则不允许取消
     * 2. 退还未售出的积分 + 对应手续费
     * 3. status改为4(已取消)
     */
    public function deleteOrder(int $orderId, int $userId)
    {
        $order = Db::name('orders')
            ->where('id', $orderId)
            ->where('user_id', $userId)
            ->find();
        if (!$order) {
            throw new ValidateException('订单不存在');
        }

        if ((int)$order['status'] === 3) {
            throw new ValidateException('已完成的订单不可取消');
        }

        if ((int)$order['status'] === 4) {
            throw new ValidateException('订单已取消');
        }

        // 检查是否有正在进行的交易（买家待支付/未确认）
        $pendingCount = Db::name('transactions')
            ->where('order_id', $orderId)
            ->where('status', 1) // 1=待支付
            ->count();
        if ($pendingCount > 0) {
            throw new ValidateException('该订单有正在进行的交易，买家尚未确认，无法取消');
        }

        // 检查是否有冻结中的交易（争议订单）
        $frozenCount = Db::name('transactions')
            ->where('order_id', $orderId)
            ->where('status', 7) // 7=冻结中
            ->count();
        if ($frozenCount > 0) {
            throw new ValidateException('该订单买家已确认付款，存在争议，系统30分钟后做出判断，订单临时冻结！');
        }

        // 退还未售出的积分（用remaining_amount而非points_amount，修复小数截断bug）
        $releaseAmount = (float)$order['remaining_amount'];
        if ($releaseAmount > 0) {
            // 退还对应手续费（按比例）
            $feeRate = (float)($order['fee_rate'] ?? 0);
            $releaseFee = round($releaseAmount * $feeRate, 2);
            $totalRelease = round($releaseAmount + $releaseFee, 2);

            Db::name('users')
                ->where('id', $userId)
                ->inc('balance', $totalRelease)
                ->update();
        }

        Db::name('orders')->where('id', $orderId)->update([
            'status'     => 4,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return true;
    }

    /**
     * 获取市场挂单列表
     */
    /**
     * 获取市场挂单列表
     */
    public function getMarketList(int $page = 1, int $pageSize = 20): array
    {
        $twentyMinutesAgo = date('Y-m-d H:i:s', time() - 1200);

        $query = Db::table('orders o')
            ->join('users u', 'u.id = o.user_id')
            ->join('payment_methods pm', 'pm.id = o.payment_method_id', 'LEFT')
            ->where('o.status', 1)
            ->where('o.remaining_amount', '>', 0)
            ->where(function($q) use ($twentyMinutesAgo) {
                // 订单未锁定（locked_by=0 或 NULL）或锁定已过期
                $q->where(function($q2) {
                    $q2->where('o.locked_by', 0)->whereOr('o.locked_by', 'null');
                })->whereOr(function($q2) use ($twentyMinutesAgo) {
                    $q2->where('o.locked_at', 'null')->whereOr('o.locked_at', '<', $twentyMinutesAgo);
                });
            })
            ->whereNotExists(function($q) {
                $q->name('complaint')
                  ->whereRaw('complaint.order_id = o.id')
                  ->where('status', '<', 2);
            })
            ->field([
                'o.id', 'o.order_no', 'o.points_amount', 'o.remaining_amount',
                'o.unit_price', 'o.total_amount', 'o.fee_rate',
                'o.status', 'o.created_at', 'o.allow_split',
                'u.nickname as seller_name',
                'pm.type as payment_type',
                Db::raw('(SELECT IFNULL(ROUND((COUNT(*) - SUM(CASE WHEN status IN (4,6) THEN 1 ELSE 0 END)) / COUNT(*) * 100, 1), 0) FROM orders WHERE user_id = o.user_id) as completion_rate'),
            ])
            ->order('o.created_at', 'desc');

        $total = $query->count();
        $list = $query->page($page, $pageSize)->select();

        return [
            'list' => $list,
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
        ];
    }

}
