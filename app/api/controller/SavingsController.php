<?php
declare(strict_types=1);

namespace app\api\controller;

use app\service\SavingsService;
use think\facade\Db;
use think\Request;

/**
 * 积分宝（用户端 API，路由前缀 /api/savings）
 * 接口统一响应 code=0 成功
 */
class SavingsController
{
    /**
     * 兼容接口：提前赎回规则已改为项目级（见 projects / orders 返回字段）
     * 此接口保留给旧版 APP，固定返回"允许提前赎回、不扣本金"
     */
    public function config(Request $request)
    {
        try {
            return json(['code' => 0, 'msg' => 'ok', 'data' => [
                'allow_early_redeem'  => true,
                'early_redeem_deduct' => false,
                'early_redeem_reward_policy' => 0,
            ]]);
        } catch (\Throwable $e) {
            return json(['code' => 500, 'msg' => '系统内部错误', 'data' => null]);
        }
    }

    /**
     * 进行中的项目列表（首页）
     */
    public function projects(Request $request)
    {
        try {
            $now = date('Y-m-d H:i:s');
            $list = Db::name('savings_projects')
                ->where('status', 1)
                ->where(function ($q) use ($now) {
                    $q->whereNull('start_time')->whereOr('start_time', '<=', $now);
                })
                ->where(function ($q) use ($now) {
                    $q->whereNull('end_time')->whereOr('end_time', '>=', $now);
                })
                ->field('id,name,description,banner_image,duration_days,reward_rate,total_quota,sold_quota,min_purchase,max_purchase,allow_early_redeem,early_redeem_deduct_rate,early_redeem_reward_policy,start_time,end_time')
                ->order('id', 'desc')
                ->select()->toArray();

            foreach ($list as &$p) {
                $p['reward_rate'] = (float)$p['reward_rate'];
                $p['total_quota'] = (float)$p['total_quota'];
                $p['sold_quota'] = (float)$p['sold_quota'];
                $p['remaining_quota'] = round(max(0, $p['total_quota'] - $p['sold_quota']), 2);
                $p['sold_percent'] = $p['total_quota'] > 0
                    ? round(min(100, $p['sold_quota'] / $p['total_quota'] * 100), 1)
                    : 0;
                $p['min_purchase'] = (float)$p['min_purchase'];
                $p['max_purchase'] = (float)$p['max_purchase'];
                // 提前赎回规则（项目级）
                $p['allow_early_redeem'] = (int)($p['allow_early_redeem'] ?? 1) === 1;
                $p['early_redeem_deduct_rate'] = (float)($p['early_redeem_deduct_rate'] ?? 0);
                $p['early_redeem_reward_policy'] = (int)($p['early_redeem_reward_policy'] ?? 0);
                // 售罄标记
                $p['sold_out'] = $p['remaining_quota'] <= 0;
            }
            unset($p);

            return json(['code' => 0, 'msg' => 'ok', 'data' => ['list' => $list]]);
        } catch (\Throwable $e) {
            return json(['code' => 500, 'msg' => '系统内部错误', 'data' => null]);
        }
    }

    /**
     * 项目详情
     */
    public function projectDetail(Request $request, $id)
    {
        try {
            $p = Db::name('savings_projects')->where('id', (int)$id)->find();
            if (!$p) {
                return json(['code' => 404, 'msg' => '项目不存在', 'data' => null]);
            }
            $p['reward_rate'] = (float)$p['reward_rate'];
            $p['total_quota'] = (float)$p['total_quota'];
            $p['sold_quota'] = (float)$p['sold_quota'];
            $p['remaining_quota'] = round(max(0, $p['total_quota'] - $p['sold_quota']), 2);
            $p['sold_percent'] = $p['total_quota'] > 0
                ? round(min(100, $p['sold_quota'] / $p['total_quota'] * 100), 1)
                : 0;
            $p['min_purchase'] = (float)$p['min_purchase'];
            $p['max_purchase'] = (float)$p['max_purchase'];
            // 提前赎回规则（项目级）
            $p['allow_early_redeem'] = (int)($p['allow_early_redeem'] ?? 1) === 1;
            $p['early_redeem_deduct_rate'] = (float)($p['early_redeem_deduct_rate'] ?? 0);
            $p['early_redeem_reward_policy'] = (int)($p['early_redeem_reward_policy'] ?? 0);

            // 当前用户已认购数量（限购提示用）
            $myPurchased = 0;
            $myCount = 0;
            if ($request->uid) {
                $myCount = (int)Db::name('savings_orders')
                    ->where('user_id', $request->uid)->where('project_id', (int)$id)
                    ->whereIn('status', [0, 1, 2])->count();
                $myPurchased = (float)Db::name('savings_orders')
                    ->where('user_id', $request->uid)->where('project_id', (int)$id)
                    ->whereIn('status', [0, 1, 2])->sum('principal');
            }
            $p['my_purchased_count'] = $myCount;
            $p['my_purchased_amount'] = $myPurchased;

            return json(['code' => 0, 'msg' => 'ok', 'data' => $p]);
        } catch (\Throwable $e) {
            return json(['code' => 500, 'msg' => '系统内部错误', 'data' => null]);
        }
    }

    /**
     * 我的资产汇总
     */
    public function summary(Request $request)
    {
        try {
            $uid = (int)$request->uid;
            $holding = Db::name('savings_orders')->where('user_id', $uid)->where('status', 0);
            $holdingPrincipal = (float)(clone $holding)->sum('principal');
            $holdingExpected = (float)(clone $holding)->sum('expected_reward');
            $holdingCount = (clone $holding)->count();

            // 累计已得奖励（已结算订单的 actual_reward）
            $totalReward = (float)Db::name('savings_orders')
                ->where('user_id', $uid)->whereIn('status', [1, 2])
                ->sum('actual_reward');
            // 累计认购
            $totalPrincipal = (float)Db::name('savings_orders')
                ->where('user_id', $uid)->whereIn('status', [0, 1, 2])
                ->sum('principal');
            // 最近到期时间
            $nextMaturity = Db::name('savings_orders')
                ->where('user_id', $uid)->where('status', 0)
                ->order('maturity_at', 'asc')->value('maturity_at');

            return json(['code' => 0, 'msg' => 'ok', 'data' => [
                'holding_principal'  => round($holdingPrincipal, 2),
                'holding_expected'   => round($holdingExpected, 2),
                'holding_count'      => $holdingCount,
                'total_reward'       => round($totalReward, 2),
                'total_principal'    => round($totalPrincipal, 2),
                'next_maturity_at'   => $nextMaturity ?: '',
            ]]);
        } catch (\Throwable $e) {
            return json(['code' => 500, 'msg' => '系统内部错误', 'data' => null]);
        }
    }

    /**
     * 我的认购记录（持有中 + 已结算）
     * status: 0=持有中 1=已到期 2=已提前赎回；不传=全部
     */
    public function myOrders(Request $request)
    {
        try {
            $uid = (int)$request->uid;
            $page = (int)$request->param('page', 1);
            $limit = (int)$request->param('limit', 15);
            $status = $request->param('status', '');

            $query = Db::name('savings_orders')->alias('o')
                ->leftJoin('savings_projects p', 'p.id = o.project_id')
                ->where('o.user_id', $uid)
                ->field('o.id,o.order_no,o.project_id,p.name as project_name,o.principal,o.reward_rate,o.duration_days,o.expected_reward,o.actual_reward,o.status,o.purchased_at,o.maturity_at,o.settled_at,o.settle_type,o.allow_early_redeem,o.early_redeem_deduct_rate,o.early_redeem_reward_policy,o.deduct_principal');
            if ($status !== '' && $status !== null) {
                $query->where('o.status', (int)$status);
            }
            $total = (clone $query)->count();
            $list = $query->order('o.id', 'desc')->page($page, $limit)->select()->toArray();
            foreach ($list as &$r) {
                $r['principal'] = (float)$r['principal'];
                $r['reward_rate'] = (float)$r['reward_rate'];
                $r['expected_reward'] = (float)$r['expected_reward'];
                $r['actual_reward'] = (float)$r['actual_reward'];
                $r['deduct_principal'] = (float)($r['deduct_principal'] ?? 0);
                $r['early_redeem_deduct_rate'] = (float)($r['early_redeem_deduct_rate'] ?? 0);
                $r['early_redeem_reward_policy'] = (int)($r['early_redeem_reward_policy'] ?? 0);
                $r['allow_early_redeem'] = (int)($r['allow_early_redeem'] ?? 1) === 1;
            }
            unset($r);

            return json(['code' => 0, 'msg' => 'ok', 'data' => ['list' => $list, 'total' => $total]]);
        } catch (\Throwable $e) {
            return json(['code' => 500, 'msg' => '系统内部错误', 'data' => null]);
        }
    }

    /**
     * 认购
     * 参数: project_id, amount, pay_password
     */
    public function purchase(Request $request)
    {
        try {
            $uid = (int)$request->uid;
            $projectId = (int)$request->param('project_id', 0);
            $amount = (float)$request->param('amount', 0);
            $payPassword = trim((string)$request->param('pay_password', ''));

            if ($projectId <= 0) {
                return json(['code' => 400, 'msg' => '请选择项目', 'data' => null]);
            }
            if ($amount <= 0) {
                return json(['code' => 400, 'msg' => '请输入认购数量', 'data' => null]);
            }

            $service = new SavingsService();
            $result = $service->purchase($uid, $projectId, $amount, $payPassword);
            return json(['code' => 0, 'msg' => '认购成功', 'data' => $result]);
        } catch (\think\exception\ValidateException $e) {
            return json(['code' => 400, 'msg' => $e->getMessage(), 'data' => null]);
        } catch (\Throwable $e) {
            \think\facade\Log::error('积分宝认购失败: ' . $e->getMessage());
            return json(['code' => 500, 'msg' => '系统内部错误', 'data' => null]);
        }
    }

    /**
     * 提前赎回
     * 参数: order_id
     */
    public function redeem(Request $request)
    {
        try {
            $uid = (int)$request->uid;
            $orderId = (int)$request->param('order_id', 0);
            if ($orderId <= 0) {
                return json(['code' => 400, 'msg' => '参数错误', 'data' => null]);
            }
            $service = new SavingsService();
            $result = $service->earlyRedeem($uid, $orderId);
            return json(['code' => 0, 'msg' => '赎回成功', 'data' => $result]);
        } catch (\think\exception\ValidateException $e) {
            return json(['code' => 400, 'msg' => $e->getMessage(), 'data' => null]);
        } catch (\Throwable $e) {
            \think\facade\Log::error('积分宝赎回失败: ' . $e->getMessage());
            return json(['code' => 500, 'msg' => '系统内部错误', 'data' => null]);
        }
    }
}
