<?php
declare(strict_types=1);

namespace app\api\controller;

use app\BaseController;
use app\model\GameConfig;
use app\model\GamePrize;
use app\model\GameRecord;
use app\model\User;
use think\facade\Db;
use think\facade\Log;
use think\response\Json;

class GameController extends BaseController
{
    /**
     * 获取可用游戏列表
     * GET /game/list
     */
    public function list(): Json
    {
        $userId = intval(request()->uid ?? 0);
        if (!$userId) {
            return json(['code' => 401, 'msg' => '请先登录', 'data' => null]);
        }

        $games = GameConfig::where('enabled', 1)
            ->order('sort', 'asc')
            ->select();

        $result = [];
        foreach ($games as $game) {
            $todayCount = GameRecord::where('user_id', $userId)
                ->where('game_type', $game->game_type)
                ->whereTime('created_at', 'today')
                ->count();

            $canPlay = $this->checkTrigger($userId, $game);
            $dailyLimit = intval($game->daily_limit);
            $dailyRemaining = $dailyLimit > 0 ? max(0, $dailyLimit - $todayCount) : -1; // -1=不限

            $result[] = [
                'game_type' => $game->game_type,
                'game_name' => $game->game_name,
                'game_desc' => $game->game_desc,
                'trigger_type' => $game->trigger_type,
                'trigger_value' => floatval($game->trigger_value),
                'cost_type' => $game->cost_type,
                'cost_value' => floatval($game->cost_value),
                'daily_limit' => $dailyLimit,
                'today_count' => $todayCount,
                'daily_remaining' => $dailyRemaining,
                'can_play' => $canPlay['can_play'],
                'trigger_msg' => $canPlay['msg'],
            ];
        }

        return json(['code' => 0, 'msg' => 'success', 'data' => $games ? $result : []]);
    }

    /**
     * 获取游戏配置和奖品(前端展示用)
     * GET /game/config?game_type=wheel
     */
    public function config(): Json
    {
        $userId = intval(request()->uid ?? 0);
        if (!$userId) {
            return json(['code' => 401, 'msg' => '请先登录', 'data' => null]);
        }

        $gameType = input('game_type', '');
        if (empty($gameType)) {
            return json(['code' => 400, 'msg' => '缺少游戏类型', 'data' => null]);
        }

        $game = GameConfig::where('game_type', $gameType)->find();
        if (!$game) {
            return json(['code' => 404, 'msg' => '游戏不存在', 'data' => null]);
        }

        $prizes = GamePrize::where('game_type', $gameType)
            ->where('enabled', 1)
            ->order('sort', 'asc')
            ->select();

        $user = User::find($userId);
        $todayCount = GameRecord::where('user_id', $userId)
            ->where('game_type', $gameType)
            ->whereTime('created_at', 'today')
            ->count();

        $canPlay = $this->checkTrigger($userId, $game);
        $dailyLimit = intval($game->daily_limit);
        $dailyRemaining = $dailyLimit > 0 ? max(0, $dailyLimit - $todayCount) : -1;

        return json([
            'code' => 0,
            'msg' => 'success',
            'data' => [
                'game' => [
                    'game_type' => $game->game_type,
                    'game_name' => $game->game_name,
                    'game_desc' => $game->game_desc,
                    'enabled' => intval($game->enabled),
                    'trigger_type' => $game->trigger_type,
                    'trigger_value' => floatval($game->trigger_value),
                    'cost_type' => $game->cost_type,
                    'cost_value' => floatval($game->cost_value),
                    'daily_limit' => $dailyLimit,
                    'today_count' => $todayCount,
                    'daily_remaining' => $dailyRemaining,
                    'can_play' => $canPlay['can_play'],
                    'trigger_msg' => $canPlay['msg'],
                ],
                'prizes' => $prizes->map(function ($p) {
                    return [
                        'id' => $p->id,
                        'prize_name' => $p->prize_name,
                        'prize_type' => $p->prize_type,
                        'prize_value' => floatval($p->prize_value),
                        'probability' => intval($p->probability),
                        'icon' => $p->icon,
                        'sort' => intval($p->sort),
                    ];
                }),
                'user_balance' => $user ? floatval($user->balance) : 0,
            ]
        ]);
    }

    /**
     * 玩游戏
     * POST /game/play
     * body: {game_type: "wheel"}
     */
    public function play(): Json
    {
        $userId = intval(request()->uid ?? 0);
        if (!$userId) {
            return json(['code' => 401, 'msg' => '请先登录', 'data' => null]);
        }

        $gameType = input('game_type', '');
        if (empty($gameType)) {
            return json(['code' => 400, 'msg' => '缺少游戏类型', 'data' => null]);
        }

        $game = GameConfig::where('game_type', $gameType)->find();
        if (!$game || intval($game->enabled) !== 1) {
            return json(['code' => 403, 'msg' => '游戏未开启', 'data' => null]);
        }

        // 触发条件(解锁条件)在事务外检查即可，不涉及并发
        $triggerCheck = $this->checkTrigger($userId, $game);
        if (!$triggerCheck['can_play']) {
            return json(['code' => 403, 'msg' => $triggerCheck['msg'], 'data' => null]);
        }

        $dailyLimit = intval($game->daily_limit);
        $costType = $game->cost_type;
        $costValue = floatval($game->cost_value);

        // 抽取奖品(纯随机，不涉及并发)
        $prize = $this->drawPrize($gameType);
        if (!$prize) {
            return json(['code' => 500, 'msg' => '抽奖失败，请稍后重试', 'data' => null]);
        }

        $prizeType = $prize['prize_type'];
        $prizeValue = floatval($prize['prize_value']);

        // 事务 + 行锁：防止同一用户并发玩游戏绕过限制
        Db::startTrans();
        try {
            // 锁定用户行，其他请求会等待
            $userRow = Db::name('users')->where('id', $userId)->lock(true)->find();
            if (!$userRow) {
                Db::rollback();
                return json(['code' => 404, 'msg' => '用户不存在', 'data' => null]);
            }

            // 事务内重新检查每日限制（防止并发绕过）
            if ($dailyLimit > 0) {
                $todayCount = Db::name('game_records')
                    ->where('user_id', $userId)
                    ->where('game_type', $gameType)
                    ->whereTime('created_at', 'today')
                    ->count();
                if ($todayCount >= $dailyLimit) {
                    Db::rollback();
                    return json(['code' => 403, 'msg' => "今日次数已用完(限{$dailyLimit}次)", 'data' => null]);
                }
            }

            // 事务内重新检查余额（防止并发绕过）
            if (($costType === 'points' || $costType === 'balance') && $costValue > 0) {
                if (floatval($userRow['balance']) < $costValue) {
                    Db::rollback();
                    return json(['code' => 403, 'msg' => "余额不足，需要{$costValue}积分", 'data' => null]);
                }
                // 扣除消耗（使用Db::raw，和系统其他模块保持一致）
                Db::name('users')->where('id', $userId)->update([
                    'balance' => Db::raw("balance - {$costValue}")
                ]);
            }

            // 发放奖品
            if (($prizeType === 'points' || $prizeType === 'balance') && $prizeValue > 0) {
                Db::name('users')->where('id', $userId)->update([
                    'balance' => Db::raw("balance + {$prizeValue}")
                ]);
            }

            // 记录
            $now = date('Y-m-d H:i:s');
            $record = [
                'user_id' => $userId,
                'game_type' => $gameType,
                'prize_id' => $prize['id'] ?? null,
                'prize_name' => $prize['prize_name'],
                'prize_type' => $prizeType,
                'prize_value' => $prizeValue,
                'cost_type' => $costType,
                'cost_value' => $costValue,
                'created_at' => $now,
            ];
            Db::name('game_records')->insert($record);

            // 中奖写入余额日志
            if (($prizeType === 'points' || $prizeType === 'balance') && $prizeValue > 0) {
                Db::name('balance_logs')->insert([
                    'user_id' => $userId,
                    'type' => 'game_prize',
                    'amount' => $prizeValue,
                    'description' => "游戏「{$gameType}」中奖：{$prize['prize_name']} +{$prizeValue}积分",
                    'created_at' => $now,
                ]);
            }
            // 消耗写入余额日志
            if (($costType === 'points' || $costType === 'balance') && $costValue > 0) {
                Db::name('balance_logs')->insert([
                    'user_id' => $userId,
                    'type' => 'game_cost',
                    'amount' => -$costValue,
                    'description' => "游戏「{$gameType}」消耗 -{$costValue}积分",
                    'created_at' => $now,
                ]);
            }

            Db::commit();
        } catch (\Exception $e) {
            Db::rollback();
            Log::error('游戏记录失败: ' . $e->getMessage());
            return json(['code' => 500, 'msg' => '系统错误，请稍后重试', 'data' => null]);
        }

        // 查最新余额
        $newBalance = floatval(Db::name('users')->where('id', $userId)->value('balance'));

        // 水果铃铛：根据中奖奖品返回对应转轮符号
        $reelSymbols = null;
        if ($gameType === 'slot') {
            try {
                $reelSymbols = $this->buildSlotReelSymbols($prize, $prizeType, $prizeValue);
            } catch (\Throwable $e) {
                // 符号生成失败不影响奖金发放，返回null让前端随机展示
                Log::error('转轮符号生成失败: ' . $e->getMessage());
                $reelSymbols = null;
            }
        }

        return json([
            'code' => 0,
            'msg' => 'success',
            'data' => [
                'prize_name' => $prize['prize_name'],
                'prize_type' => $prizeType,
                'prize_value' => $prizeValue,
                'amount' => $prizeValue,
                'is_win' => ($prizeType !== 'none' && $prizeValue > 0),
                'icon' => $prize['icon'] ?? '',
                'balance' => $newBalance,
                'symbols' => $reelSymbols,
            ]
        ]);
    }

    /**
     * 根据水果铃铛中奖结果生成三个转轮符号
     * 中奖：三个相同的对应水果符号
     * 未中奖：三个不全相同的随机符号
     */
    private function buildSlotReelSymbols(array $prize, string $prizeType, float $prizeValue): array
    {
        // 奖品名到emoji的映射
        $symbolMap = [
            '樱桃' => '🍒',
            '柠檬' => '🍋',
            '葡萄' => '🍇',
            '西瓜' => '🍉',
            '铃铛' => '🔔',
            '777' => '7️⃣',
            '7' => '7️⃣',
        ];

        $allSymbols = ['🍒', '🍋', '🍇', '🍉', '🔔', '7️⃣'];

        // 中奖：返回三个相同符号
        if ($prizeType !== 'none' && $prizeValue > 0) {
            $winSymbol = '🍒'; // 默认
            foreach ($symbolMap as $keyword => $emoji) {
                if (strpos($prize['prize_name'], $keyword) !== false) {
                    $winSymbol = $emoji;
                    break;
                }
            }
            return [$winSymbol, $winSymbol, $winSymbol];
        }

        // 未中奖：随机三个，确保不全相同
        $symbols = [];
        for ($i = 0; $i < 3; $i++) {
            $symbols[] = $allSymbols[mt_rand(0, count($allSymbols) - 1)];
        }
        // 万一三个全一样，换掉第二个
        if ($symbols[0] === $symbols[1] && $symbols[1] === $symbols[2]) {
            $idx = array_search($symbols[0], $allSymbols);
            $symbols[1] = $allSymbols[($idx + mt_rand(1, 5)) % count($allSymbols)];
        }
        return $symbols;
    }

    /**
     * 获取用户游戏记录
     * GET /game/records?game_type=wheel&page=1
     */
    public function records(): Json
    {
        $userId = intval(request()->uid ?? 0);
        if (!$userId) {
            return json(['code' => 401, 'msg' => '请先登录', 'data' => null]);
        }

        $gameType = input('game_type', '');
        $page = max(1, intval(input('page', 1)));
        $pageSize = 20;

        $query = GameRecord::where('user_id', $userId);
        if (!empty($gameType)) {
            $query = $query->where('game_type', $gameType);
        }

        $total = $query->count();
        $list = $query->order('created_at', 'desc')
            ->page($page, $pageSize)
            ->select();

        return json([
            'code' => 0,
            'msg' => 'success',
            'data' => [
                'list' => $list->map(function ($r) {
                    return [
                        'id' => $r->id,
                        'game_type' => $r->game_type,
                        'prize_name' => $r->prize_name,
                        'prize_type' => $r->prize_type,
                        'prize_value' => floatval($r->prize_value),
                        'is_win' => ($r->prize_type !== 'none' && floatval($r->prize_value) > 0),
                        'cost_type' => $r->cost_type,
                        'cost_value' => floatval($r->cost_value),
                        'created_at' => $r->created_at,
                    ];
                }),
                'total' => $total,
                'page' => $page,
                'page_size' => $pageSize,
                'has_more' => ($page * $pageSize) < $total,
            ]
        ]);
    }

    /**
     * 检查触发条件(解锁条件)
     */
    private function checkTrigger(int $userId, $game): array
    {
        $triggerType = $game->trigger_type;
        $triggerValue = floatval($game->trigger_value);

        if ($triggerType === 'none' || $triggerType === '') {
            return ['can_play' => true, 'msg' => ''];
        }

        if ($triggerType === 'trade_count') {
            // 已完成交易笔数 >= trigger_value
            $count = Db::name('transactions')
                ->where(function ($q) use ($userId) {
                    $q->where('buyer_id', $userId)->whereOr('seller_id', $userId);
                })
                ->where('status', 3) // 已完成
                ->count();
            if ($count < $triggerValue) {
                return ['can_play' => false, 'msg' => "需完成{$triggerValue}笔交易后解锁"];
            }
            return ['can_play' => true, 'msg' => ''];
        }

        if ($triggerType === 'recharge_amount') {
            // 累计充值金额 >= trigger_value
            $total = Db::name('recharge_orders')
                ->where('user_id', $userId)
                ->where('status', 1) // 已支付
                ->sum('amount');
            if (floatval($total) < $triggerValue) {
                return ['can_play' => false, 'msg' => "需累计充值{$triggerValue}后解锁"];
            }
            return ['can_play' => true, 'msg' => ''];
        }

        if ($triggerType === 'points_exchange') {
            // 用积分兑换次数，cost_type=points, cost_value=每次消耗
            return ['can_play' => true, 'msg' => ''];
        }

        if ($triggerType === 'balance') {
            // 用余额玩，cost_type=balance, cost_value=每次消耗
            return ['can_play' => true, 'msg' => ''];
        }

        return ['can_play' => true, 'msg' => ''];
    }

    /**
     * 按概率抽取奖品
     * 每个奖品有千分比概率(0-1000)，总和不超过1000
     */
    private function drawPrize(string $gameType): ?array
    {
        $prizes = GamePrize::where('game_type', $gameType)
            ->where('enabled', 1)
            ->order('sort', 'asc')
            ->select();

        if ($prizes->isEmpty()) {
            return null;
        }

        $totalProb = 0;
        $prizeList = [];
        foreach ($prizes as $p) {
            $prob = intval($p->probability);
            if ($prob > 0) {
                $totalProb += $prob;
                $prizeList[] = [
                    'id' => $p->id,
                    'prize_name' => $p->prize_name,
                    'prize_type' => $p->prize_type,
                    'prize_value' => floatval($p->prize_value),
                    'probability' => $prob,
                    'icon' => $p->icon,
                    'sort' => intval($p->sort),
                ];
            }
        }

        if ($totalProb <= 0) {
            // 全部概率为0，返回第一个奖品(通常是"谢谢参与")
            $first = $prizes[0];
            return [
                'id' => $first->id,
                'prize_name' => $first->prize_name,
                'prize_type' => $first->prize_type,
                'prize_value' => floatval($first->prize_value),
                'icon' => $first->icon,
            ];
        }

        // 随机数 1 到 totalProb
        $rand = mt_rand(1, $totalProb);
        $accumulated = 0;
        foreach ($prizeList as $p) {
            $accumulated += $p['probability'];
            if ($rand <= $accumulated) {
                return $p;
            }
        }

        // 兜底返回最后一个
        return $prizeList[count($prizeList) - 1];
    }

    /**
     * 从请求头获取用户ID
     */
}
