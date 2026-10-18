<?php
declare(strict_types=1);

// 控制台配置
return [
    'commands' => [
        // 积分宝到期自动结算：php think savings:settle
        'savings:settle' => \app\command\SavingsSettle::class,
        //自动清空超过20分钟没有支付的订单
         'order:auto-release' => \app\command\AutoReleaseLock::class,
        ],
];
