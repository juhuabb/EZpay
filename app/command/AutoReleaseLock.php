<?php
declare(strict_types=1);

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\facade\Db;
use think\facade\Log;

/**
 * 自动释放过期订单锁定 + 取消超时待付款交易
 * 
 * 逻辑：
 *   1. 找到所有锁定超过20分钟且未清除的订单，重置 locked_by=0, locked_at=NULL
 *   2. 找到这些订单下状态为"待付款"(status=1)的交易记录，自动标记为"已取消"(status=4)
 * 
 * 宝塔 crontab 示例（每分钟执行一次）：
 *   * * * * * cd /www/wwwroot/wjfserver.paywjf.com && php think order:auto-release >> /dev/null 2>&1
 */
class AutoReleaseLock extends Command
{
    protected function configure()
    {
        $this->setName('order:auto-release')
            ->setDescription('自动释放过期订单锁定，取消超时待付款交易');
    }

    protected function execute(Input $input, Output $output)
    {
        $now = time();
        $expireSeconds = 1200; // 20分钟
        $expireTime = date('Y-m-d H:i:s', $now - $expireSeconds);

        // ========== 第一步：释放过期锁定 ==========
        // 找出所有锁定已过期但还没清除的订单（status=1 表示挂单中）
        $expiredOrders = Db::name('orders')
            ->where('status', 1)
            ->where('locked_by', '>', 0)
            ->where('locked_at', '<=', $expireTime)
            ->column('id');

        $releasedCount = 0;
        if (!empty($expiredOrders)) {
            // 清除锁定状态
            Db::name('orders')
                ->whereIn('id', $expiredOrders)
                ->update([
                    'locked_by'  => 0,
                    'locked_at'  => null,
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
            $releasedCount = count($expiredOrders);
        }

        // ========== 第二步：取消超时待付款交易 ==========
        // 找出所有超过20分钟仍处于"待付款"状态(status=1)的交易
        $cancelledCount = 0;
        $pendingTrans = Db::name('transactions')
            ->where('status', 1) // 待付款
            ->where('created_at', '<=', $expireTime)
            ->column('id');

        if (!empty($pendingTrans)) {
            Db::name('transactions')
                ->whereIn('id', $pendingTrans)
                ->update([
                    'status'     => 4, // 已取消
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
            $cancelledCount = count($pendingTrans);
        }

        // ========== 输出日志 ==========
        $msg = '[' . date('Y-m-d H:i:s') . '] 自动释放：解锁' . $releasedCount . '单，取消' . $cancelledCount . '笔超时交易';
        $output->writeln($msg);

        if ($releasedCount > 0 || $cancelledCount > 0) {
            Log::info($msg);
        }
    }
}
