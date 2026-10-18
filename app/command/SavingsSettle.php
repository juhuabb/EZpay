<?php
declare(strict_types=1);

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\facade\Db;
use think\facade\Log;
use app\service\SavingsService;

/**
 * 积分宝到期自动结算命令
 * 宝塔 crontab 示例（每分钟执行一次，到期即结算）：
 *   * * * * * cd /www/wwwroot/你的站点目录 && php think savings:settle >> /dev/null 2>&1
 * 幂等设计：仅扫描 status=0 且已到期的订单，结算时行锁+事务，重复执行不会重复发奖
 */
class SavingsSettle extends Command
{
    protected function configure()
    {
        $this->setName('savings:settle')
            ->setDescription('积分宝到期订单自动结算：本金回balance，奖励进bonus');
    }

    protected function execute(Input $input, Output $output)
    {
        $now = date('Y-m-d H:i:s');
        $settled = 0;
        $failed = 0;

        // 分批扫描到期未结算订单，每批100条
        while (true) {
            $orders = Db::name('savings_orders')
                ->where('status', 0)
                ->where('maturity_at', '<=', $now)
                ->order('id', 'asc')
                ->limit(100)
                ->select()->toArray();

            if (empty($orders)) {
                break;
            }

            $service = new SavingsService();
            foreach ($orders as $order) {
                try {
                    $service->settleOrder($order, 1);
                    $settled++;
                } catch (\Throwable $e) {
                    // 标记为待人工处理，避免反复重试阻塞后续订单
                    $failed++;
                    Db::name('savings_orders')->where('id', $order['id'])->update([
                        'status'     => 3,
                        'updated_at' => $now,
                    ]);
                    Log::error('积分宝结算失败 订单#' . $order['id'] . '：' . $e->getMessage());
                }
            }

            if (count($orders) < 100) {
                break;
            }
        }

        $msg = '[' . $now . '] 积分宝结算完成：成功' . $settled . '笔，失败' . $failed . '笔';
        $output->writeln($msg);
        Log::info($msg);
    }
}
