<?php
declare(strict_types=1);

namespace app\service;

use app\model\User;
use think\facade\Db;
use think\exception\ValidateException;

/**
 * 积分宝服务
 * 资金规则（users 表 balance=总余额 / principal=本金 / bonus=赠送）：
 *  - 认购：仅扣 balance；principal、bonus 不动
 *  - 到期结算 / 提前赎回本金：仅加 balance；principal 不动
 *  - 奖励（仅到期）：仅加 bonus；balance、principal 不动
 * 提前赎回规则（项目级，发布项目时配置，下单时快照进订单）：
 *  - allow_early_redeem：该项目是否允许提前赎回（0=不允许，只能持有到期）
 *  - early_redeem_deduct_rate：提前赎回扣除本金比例%（0-100，0=不扣本金）
 *  - early_redeem_reward_policy：提前赎回奖励策略（0=不发/1=按持有天数折算/2=全额发放）
 *  - 提前赎回奖励由 policy 控制：0=不发（默认防刷）/1=按持有天数折算/2=全额发放；扣除的本金不返还（平台收入）
 * 所有余额变动均写 balance_logs 流水，并同步写站内信
 */
class SavingsService
{
    /** 订单号前缀 */
    const ORDER_PREFIX = 'SB';

    /**
     * 生成订单号：SB + 年月日 + 6位随机
     */
    public static function makeOrderNo(): string
    {
        return self::ORDER_PREFIX . date('YmdHis') . str_pad((string)mt_rand(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    /**
     * 用户认购
     * @param int    $userId
     * @param int    $projectId
     * @param float  $amount      认购积分
     * @param string $payPassword 支付密码（用户已设置支付密码时必传）
     * @return array 订单信息
     * @throws ValidateException
     */
    public function purchase(int $userId, int $projectId, float $amount, string $payPassword = ''): array
    {
        if ($amount <= 0) {
            throw new ValidateException('认购数量必须大于0');
        }
        // 金额保留2位小数
        $amount = round($amount, 2);

        // 用户校验 + 支付密码校验
        $user = User::find($userId);
        if (!$user) {
            throw new ValidateException('用户不存在');
        }
        if ((int)$user->status !== 1) {
            throw new ValidateException('账户已被锁定，无法认购');
        }
        if (!empty($user->login_password)) {
            if ($payPassword === '') {
                throw new ValidateException('请输入支付密码');
            }
            if (!$user->verifyPayPassword($payPassword)) {
                throw new ValidateException('支付密码错误');
            }
        }

        return Db::transaction(function () use ($userId, $projectId, $amount, $user) {
            // 行锁锁项目
            $project = Db::name('savings_projects')->lock(true)->where('id', $projectId)->find();
            if (!$project) {
                throw new ValidateException('项目不存在');
            }
            if ((int)$project['status'] !== 1) {
                throw new ValidateException('项目未开放认购或已结束');
            }
            $now = date('Y-m-d H:i:s');
            if (!empty($project['start_time']) && $project['start_time'] > $now) {
                throw new ValidateException('项目尚未开售');
            }
            if (!empty($project['end_time']) && $project['end_time'] < $now) {
                throw new ValidateException('项目认购已截止');
            }

            // 认购金额上下限
            if ($amount < (float)$project['min_purchase']) {
                throw new ValidateException('最低认购' . rtrim(rtrim(number_format($project['min_purchase'], 2, '.', ''), '0'), '.') . '积分');
            }
            if ((float)$project['max_purchase'] > 0 && $amount > (float)$project['max_purchase']) {
                throw new ValidateException('单人最高认购' . rtrim(rtrim(number_format($project['max_purchase'], 2, '.', ''), '0'), '.') . '积分');
            }

            // 剩余份额
            $remaining = (float)$project['total_quota'] - (float)$project['sold_quota'];
            if ($remaining < $amount) {
                throw new ValidateException('剩余份额不足，当前可认购' . rtrim(rtrim(number_format(max($remaining, 0), 2, '.', ''), '0'), '.') . '积分');
            }

            // 单人限购次数
            if ((int)$project['per_user_limit'] === 1) {
                $exists = Db::name('savings_orders')
                    ->where('user_id', $userId)
                    ->where('project_id', $projectId)
                    ->whereIn('status', [0, 1, 2])
                    ->count();
                if ($exists > 0) {
                    throw new ValidateException('该项目每人限购1次，您已认购');
                }
            }

            // 预期奖励 = 本金 × 奖励率%
            $expectedReward = round($amount * (float)$project['reward_rate'] / 100, 2);
            $orderNo = self::makeOrderNo();
            $purchasedAt = $now;
            $maturityAt = date('Y-m-d H:i:s', strtotime('+' . (int)$project['duration_days'] . ' days'));

            // 扣款：仅扣总余额 balance（principal/bonus 不动；行锁+事务校验余额）
            $this->deductBalanceOnly($userId, $amount);

            // 项目已售份额增加
            Db::name('savings_projects')->where('id', $projectId)->update([
                'sold_quota' => Db::raw('sold_quota + ' . $amount),
                'updated_at' => $now,
            ]);

            // 创建订单（提前赎回规则随订单快照，项目后改不影响本订单）
            $allowEarlyRedeem = (int)($project['allow_early_redeem'] ?? 1) === 0 ? 0 : 1;
            $deductRate = (float)($project['early_redeem_deduct_rate'] ?? 0);
            $deductRate = max(0, min(100, $deductRate));
            $rewardPolicy = (int)($project['early_redeem_reward_policy'] ?? 0);
            $rewardPolicy = in_array($rewardPolicy, [0, 1, 2], true) ? $rewardPolicy : 0;
            $orderId = Db::name('savings_orders')->insertGetId([
                'order_no'                 => $orderNo,
                'user_id'                  => $userId,
                'project_id'               => $projectId,
                'principal'                => $amount,
                'reward_rate'              => $project['reward_rate'],
                'duration_days'            => $project['duration_days'],
                'expected_reward'          => $expectedReward,
                'actual_reward'            => 0,
                'status'                   => 0,
                'purchased_at'             => $purchasedAt,
                'maturity_at'              => $maturityAt,
                'allow_early_redeem'         => $allowEarlyRedeem,
                'early_redeem_deduct_rate'   => $deductRate,
                'early_redeem_reward_policy' => $rewardPolicy,
                'deduct_principal'           => 0,
                'created_at'               => $now,
                'updated_at'               => $now,
            ]);

            // 流水
            $this->addBalanceLog($userId, 'savings_out', -$amount,
                '积分宝认购：' . $project['name'] . '，订单' . $orderNo);

            // 站内信
            $this->addMessage($userId, '积分宝认购成功',
                '您已成功认购「' . $project['name'] . '」，认购' . $amount . '积分，期限' . (int)$project['duration_days'] . '天，预计' . $maturityAt . '到期结算，预计奖励' . $expectedReward . '积分。');

            return [
                'order_id'       => $orderId,
                'order_no'       => $orderNo,
                'principal'      => $amount,
                'expected_reward'=> $expectedReward,
                'maturity_at'    => $maturityAt,
            ];
        });
    }

    /**
     * 结算订单（内部方法，事务由调用方保证或内部开启）
     * settleType: 1=到期自动结算  2=提前赎回
     * 到期自动：本金全额回 balance + 预期奖励进 bonus
     * 提前赎回：不享受奖励；按订单快照的 early_redeem_deduct_rate 比例扣除本金，
     *           剩余本金回 balance，扣除部分不返还（平台收入）
     *
     * @return array{principal:float, reward:float, refund:float, deduct:float}
     */
    public function settleOrder(array $order, int $settleType): array
    {
        return Db::transaction(function () use ($order, $settleType) {
            // 行锁重查，防重复结算
            $locked = Db::name('savings_orders')->lock(true)->where('id', $order['id'])->find();
            if (!$locked) {
                throw new ValidateException('订单不存在');
            }
            if ((int)$locked['status'] !== 0) {
                throw new ValidateException('订单已结算');
            }

            $principal = (float)$locked['principal'];
            $expectedReward = (float)$locked['expected_reward'];
            $now = date('Y-m-d H:i:s');
            $reward = 0.0;
            $deduct = 0.0;   // 提前赎回扣除的本金
            $refund = $principal; // 实际返还的本金

            $project = Db::name('savings_projects')->where('id', $locked['project_id'])->find();
            $projectName = $project ? $project['name'] : ('项目#' . $locked['project_id']);

            $userId = (int)$locked['user_id'];

            if ($settleType === 1) {
                // 到期：本金全额 + 预期奖励
                $reward = $expectedReward;
                $this->addBalanceOnly($userId, $principal);
                if ($reward > 0) {
                    $this->addBonusOnly($userId, $reward);
                }
                $this->addBalanceLog($userId, 'savings_in', $principal,
                    '积分宝到期本金返还：' . $projectName . '，订单' . $locked['order_no']);
                if ($reward > 0) {
                    $this->addBalanceLog($userId, 'savings_reward', $reward,
                        '积分宝到期奖励：' . $projectName . '，订单' . $locked['order_no']);
                }
                $this->addMessage($userId, '积分宝到期结算',
                    '您认购的「' . $projectName . '」已到期，本金' . $principal . '积分已返还，奖励' . $reward . '积分已到账（计入赠送余额）。');
            } else {
                // 提前赎回：按订单快照的 early_redeem_deduct_rate 比例扣除本金
                // 奖励策略由 early_redeem_reward_policy 控制：
                //   0 = 不发放奖励（默认，防刷）
                //   1 = 按持有天数折算（持有秒数/到期秒数 * 预期奖励）
                //   2 = 全额发放奖励
                $rate = (float)($locked['early_redeem_deduct_rate'] ?? 0);
                $rate = max(0, min(100, $rate));
                if ($rate > 0) {
                    $deduct = round($principal * $rate / 100, 2);
                    if ($deduct > $principal) {
                        $deduct = $principal;
                    }
                }
                $refund = round($principal - $deduct, 2);

                // 计算提前赎回奖励
                $policy = (int)($locked['early_redeem_reward_policy'] ?? 0);
                if ($policy === 2) {
                    // 全额发放
                    $reward = $expectedReward;
                } elseif ($policy === 1) {
                    // 按持有天数折算
                    $purchasedTs = strtotime($locked['purchased_at']);
                    $nowTs = strtotime($now);
                    $durationDays = (int)$locked['duration_days'];
                    if ($purchasedTs && $nowTs > $purchasedTs && $durationDays > 0) {
                        $heldSeconds = $nowTs - $purchasedTs;
                        $totalSeconds = $durationDays * 86400;
                        $ratio = min(1.0, $heldSeconds / $totalSeconds);
                        $reward = round($expectedReward * $ratio, 2);
                    } else {
                        $reward = 0.0;
                    }
                } else {
                    // policy === 0：不发奖励
                    $reward = 0.0;
                }

                if ($refund > 0) {
                    $this->addBalanceOnly($userId, $refund);
                    $this->addBalanceLog($userId, 'savings_in', $refund,
                        '积分宝提前赎回本金返还：' . $projectName . '，订单' . $locked['order_no']);
                }
                if ($deduct > 0) {
                    $this->addBalanceLog($userId, 'savings_deduct', -$deduct,
                        '积分宝提前赎回扣除本金（扣除比例' . rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.') . '%）：' . $projectName . '，订单' . $locked['order_no']);
                }
                if ($reward > 0) {
                    $this->addBonusOnly($userId, $reward);
                    $this->addBalanceLog($userId, 'savings_reward', $reward,
                        '积分宝提前赎回奖励（策略' . $policy . '）：' . $projectName . '，订单' . $locked['order_no']);
                }

                // 站内信
                $rewardTip = '';
                if ($reward > 0) {
                    $rewardTip = '，奖励' . $reward . '积分已到账（计入赠送余额）';
                } else {
                    $rewardTip = '，提前赎回不享受奖励';
                }
                if ($deduct > 0) {
                    $tip = '返还本金' . $refund . '积分，按规则扣除本金' . $deduct . '积分' . $rewardTip . '。';
                } else {
                    $tip = '本金' . $refund . '积分已全额返还' . $rewardTip . '。';
                }
                $this->addMessage($userId, '积分宝提前赎回', '您已提前赎回「' . $projectName . '」，' . $tip);
            }

            Db::name('savings_orders')->where('id', $locked['id'])->update([
                'status'           => $settleType === 1 ? 1 : 2,
                'actual_reward'    => $reward,
                'deduct_principal' => $deduct,
                'settled_at'       => $now,
                'settle_type'      => $settleType === 1 ? 0 : 1,
                'updated_at'       => $now,
            ]);

            return [
                'principal' => $principal,
                'reward'    => round($reward, 2),
                'refund'    => round($refund, 2),
                'deduct'    => round($deduct, 2),
            ];
        });
    }

    /**
     * 用户提前赎回
     */
    public function earlyRedeem(int $userId, int $orderId): array
    {
        $order = Db::name('savings_orders')->where('id', $orderId)->where('user_id', $userId)->find();
        if (!$order) {
            throw new ValidateException('订单不存在');
        }
        // 规则以订单快照为准（项目发布后改规则不影响已购订单；无快照的老订单默认允许）
        if ((int)($order['allow_early_redeem'] ?? 1) !== 1) {
            throw new ValidateException('该项目不支持提前赎回，请持有到期');
        }
        if ((int)$order['status'] !== 0) {
            throw new ValidateException('订单已结算，无需赎回');
        }
        if ($order['maturity_at'] <= date('Y-m-d H:i:s')) {
            throw new ValidateException('订单已到期，等待系统自动结算');
        }
        return $this->settleOrder($order, 2);
    }

    /**
     * 认购扣款：仅扣总余额 balance（principal / bonus 均不动）
     * 行锁 + 余额校验，余额不足抛 ValidateException
     */
    private function deductBalanceOnly(int $userId, float $amount): void
    {
        $user = Db::name('users')->lock(true)->where('id', $userId)->find();
        if (!$user) {
            throw new ValidateException('用户不存在');
        }
        if ((float)$user['balance'] < $amount) {
            throw new ValidateException('积分余额不足');
        }
        $amt = number_format($amount, 2, '.', '');
        Db::name('users')->where('id', $userId)->update([
            'balance' => Db::raw('balance - ' . $amt),
        ]);
    }

    /**
     * 本金返还：仅加总余额 balance（principal / bonus 均不动）
     */
    private function addBalanceOnly(int $userId, float $amount): void
    {
        if ($amount <= 0) {
            return;
        }
        $amt = number_format($amount, 2, '.', '');
        Db::name('users')->where('id', $userId)->update([
            'balance' => Db::raw('balance + ' . $amt),
        ]);
    }

    /**
     * 奖励发放：仅加赠送余额 bonus（balance / principal 均不动）
     */
    private function addBonusOnly(int $userId, float $amount): void
    {
        if ($amount <= 0) {
            return;
        }
        $amt = number_format($amount, 2, '.', '');
        Db::name('users')->where('id', $userId)->update([
            'bonus' => Db::raw('bonus + ' . $amt),
        ]);
    }

    /**
     * 余额流水
     */
    private function addBalanceLog(int $userId, string $type, float $amount, string $description): void
    {
        Db::name('balance_logs')->insert([
            'user_id'     => $userId,
            'type'        => $type,
            'amount'      => $amount,
            'description' => $description,
            'created_at'  => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * 站内信
     */
    private function addMessage(int $userId, string $title, string $content): void
    {
        $now = date('Y-m-d H:i:s');
        Db::name('messages')->insert([
            'user_id'    => $userId,
            'title'      => $title,
            'content'    => $content,
            'type'       => 'system',
            'related_id' => 0,
            'is_read'    => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
