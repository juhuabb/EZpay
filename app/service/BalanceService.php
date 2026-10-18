<?php
declare(strict_types=1);

namespace app\service;

use app\model\User;
use think\facade\Db;
use think\exception\ValidateException;

class BalanceService
{
    /**
     * 增加余额
     * type: principal=本金, bonus=奖励, balance=余额(仅加总余额)
     */
    public function increase(int $userId, float $amount, string $type = 'principal'): bool
    {
        if ($amount <= 0) {
            throw new ValidateException('金额必须大于0');
        }

        return Db::transaction(function () use ($userId, $amount, $type) {
            $user = User::find($userId);
            if (!$user) {
                throw new ValidateException('用户不存在');
            }

            $data = ['balance' => Db::raw("balance + {$amount}")];

            if ($type === 'principal') {
                $data['principal'] = Db::raw("principal + {$amount}");
            } elseif ($type === 'bonus') {
                $data['bonus'] = Db::raw("bonus + {$amount}");
            }
            // type === 'balance' 时只加总余额，不动本金和奖励

            return $user->save($data);
        });
    }

    /**
     * 扣减余额
     * 优先扣本金，再扣奖励，剩余部分从额外余额扣减
     */
    public function decrease(int $userId, float $amount): bool
    {
        if ($amount <= 0) {
            throw new ValidateException('金额必须大于0');
        }

        return Db::transaction(function () use ($userId, $amount) {
            $user = User::lock(true)->find($userId);
            if (!$user) {
                throw new ValidateException('用户不存在');
            }

            if ($user->balance < $amount) {
                throw new ValidateException('余额不足');
            }

            // 优先扣减本金
            $fromPrincipal = min($user->principal, $amount);
            $remaining = $amount - $fromPrincipal;
            // 再扣奖励
            $fromBonus = min($user->bonus, $remaining);
            // 剩余部分从额外余额扣减（balance中超出principal+bonus的部分）

            $data = ['balance' => Db::raw("balance - {$amount}")];

            if ($fromPrincipal > 0) {
                $data['principal'] = Db::raw("principal - {$fromPrincipal}");
            }
            if ($fromBonus > 0) {
                $data['bonus'] = Db::raw("bonus - {$fromBonus}");
            }

            return $user->save($data);
        });
    }

    /**
     * 交易：买家扣款，卖家到账
     */
    public function executeTrade(int $buyerId, int $sellerId, float $amount): bool
    {
        return Db::transaction(function () use ($buyerId, $sellerId, $amount) {
            // 买家扣款
            $buyer = User::lock(true)->find($buyerId);
            if (!$buyer || $buyer->balance < $amount) {
                throw new ValidateException('买家余额不足');
            }

            $fromPrincipal = min($buyer->principal, $amount);
            $remaining = $amount - $fromPrincipal;
            $fromBonus = min($buyer->bonus, $remaining);

            $buyerData = ['balance' => Db::raw("balance - {$amount}")];
            if ($fromPrincipal > 0) {
                $buyerData['principal'] = Db::raw("principal - {$fromPrincipal}");
            }
            if ($fromBonus > 0) {
                $buyerData['bonus'] = Db::raw("bonus - {$fromBonus}");
            }
            $buyer->save($buyerData);

            // 卖家到账
            $seller = User::lock(true)->find($sellerId);
            if (!$seller) {
                throw new ValidateException('卖家不存在');
            }

            $seller->save([
                'balance' => Db::raw("balance + {$amount}"),
                'principal' => Db::raw("principal + {$amount}"),
            ]);

            return true;
        });
    }

    /**
     * 退款：从卖家扣款，退回买家
     */
    public function refund(int $buyerId, int $sellerId, float $amount): bool
    {
        return Db::transaction(function () use ($buyerId, $sellerId, $amount) {
            // 卖家扣回
            $seller = User::lock(true)->find($sellerId);
            if (!$seller || $seller->balance < $amount) {
                throw new ValidateException('卖家余额不足，无法退款');
            }

            $fromPrincipal = min($seller->principal, $amount);
            $remaining = $amount - $fromPrincipal;
            $fromBonus = min($seller->bonus, $remaining);

            $sellerData = ['balance' => Db::raw("balance - {$amount}")];
            if ($fromPrincipal > 0) {
                $sellerData['principal'] = Db::raw("principal - {$fromPrincipal}");
            }
            if ($fromBonus > 0) {
                $sellerData['bonus'] = Db::raw("bonus - {$fromBonus}");
            }
            $seller->save($sellerData);

            // 买家退回(退入本金)
            $buyer = User::lock(true)->find($buyerId);
            if (!$buyer) {
                throw new ValidateException('买家不存在');
            }

            $buyer->save([
                'balance' => Db::raw("balance + {$amount}"),
                'principal' => Db::raw("principal + {$amount}"),
            ]);

            return true;
        });
    }

    /**
     * 管理员调整余额
     * type: principal=本金, bonus=奖励, balance=余额
     */
    public function adminAdjustBalance(int $userId, float $amount, string $type = 'principal'): bool
    {
        if ($amount > 0) {
            return $this->increase($userId, abs($amount), $type);
        } else {
            return $this->decrease($userId, abs($amount));
        }
    }
}
