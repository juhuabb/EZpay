<?php
declare(strict_types=1);

namespace app\api\controller;
use think\facade\Db;
use app\model\Order;
use app\model\PaymentMethod;
use app\service\OrderService;
use think\Request;
use think\Response;
use think\exception\ValidateException;

class OrderController
{
    protected OrderService $orderService;

    public function __construct(OrderService $orderService)
    {
        $this->orderService = $orderService;
    }

    /**
     * 创建挂单
     */
    public function createOrder(Request $request): Response
    {
        try {
            $order = $this->orderService->createOrder($request->uid, $request->post());

            return json([
                'code' => 0,
                'msg' => '挂单创建成功',
                'data' => $order
            ]);
        } catch (ValidateException $e) {
            return json(['code' => 400, 'msg' => $e->getMessage(), 'data' => null]);
        }
    }

    /**
     * 更新挂单
     */
    public function updateOrder(Request $request): Response
    {
        $orderId = (int) $request->route('id');

        try {
            $order = $this->orderService->updateOrder($orderId, $request->uid, $request->post());

            return json([
                'code' => 0,
                'msg' => '挂单更新成功',
                'data' => $order
            ]);
        } catch (ValidateException $e) {
            return json(['code' => 400, 'msg' => $e->getMessage(), 'data' => null]);
        }
    }

    /**
     * 删除(下架)挂单
     */
    public function deleteOrder(Request $request): Response
    {
        $orderId = (int) ($request->route('id') ?: $request->post('id'));

        try {
            $this->orderService->deleteOrder($orderId, $request->uid);

            return json(['code' => 0, 'msg' => '挂单已下架', 'data' => null]);
        } catch (ValidateException $e) {
            return json(['code' => 400, 'msg' => $e->getMessage(), 'data' => null]);
        }
    }
    
        /**
     * 软删除订单（从"我卖"列表隐藏）
     * 仅允许已完成(3)或已取消(4)的订单
     */
    public function softDelete(Request $request): Response
    {
        $orderId = (int) ($request->post('id') ?: $request->post('order_id'));
        $userId = $request->uid;

        if (!$orderId) {
            return json(['code' => 400, 'msg' => '缺少订单ID', 'data' => null]);
        }

        $order = Db::name('orders')
            ->where('id', $orderId)
            ->where('user_id', $userId)
            ->find();

        if (!$order) {
            return json(['code' => 404, 'msg' => '订单不存在', 'data' => null]);
        }

        // 只有已完成或已取消的订单可以删除
        $status = (int) $order['status'];
        if (!in_array($status, [3, 4])) {
            return json(['code' => 400, 'msg' => '只有已完成或已取消的订单可以删除', 'data' => null]);
        }

        Db::name('orders')->where('id', $orderId)->update([
            'is_deleted' => 1,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        Db::name('transactions')->where('order_id', $orderId)->update([
            'seller_deleted' => 1,
        ]);

        return json(['code' => 0, 'msg' => '已删除', 'data' => null]);
    }

    
    

    /**
     * 我的挂单列表
     */
    public function myOrders(Request $request): Response
    {
        $page = (int) $request->get('page', 1);
        $pageSize = (int) $request->get('page_size', 20);
        $status = $request->get('status');

        $query = Order::where('user_id', $request->uid);

        if ($status !== null && $status !== '') {
            $query->where('status', (int) $status);
        }

        $list = $query->order('id', 'desc')
            ->page($page, $pageSize)
            ->select();

        $total = $query->count();

        return json([
            'code' => 0,
            'msg' => 'success',
            'data' => [
                'list' => $list,
                'total' => $total,
                'page' => $page,
            ]
        ]);
    }

    /**
     * 挂单详情（含seller_name和user_id）
     */
    public function orderDetail(Request $request): Response
    {
        $orderId = (int) $request->route('id');
        $order = Order::with(['creator' => function($q) {
            $q->field('id,username,nickname');
        }])->find($orderId);

        if (!$order) {
            return json(['code' => 404, 'msg' => '挂单不存在', 'data' => null]);
        }

        $data = $order->toArray();
        // 补充 seller_name
        $data['seller_name'] = '';
        if ($order->creator) {
            $data['seller_name'] = $order->creator->nickname ?: $order->creator->username;
        }
        // user_id 字段（前端判断卖家本人用）
        $data['user_id'] = $order->user_id;
        $data['payment_method_type'] = '';
if (!empty($order->payment_method_id)) {
    $pm = Db::name('payment_methods')->where('id', $order->payment_method_id)->find();
    if ($pm) {
        $typeMap = ['wechat' => '微信', 'alipay' => '支付宝', 'bank' => '银行卡'];
        $data['payment_method_type'] = $typeMap[$pm['type']] ?? $pm['type'];
    }
}

   
        return json(['code' => 0, 'msg' => 'success', 'data' => $data]);
    }

    /**
     * 市场挂单列表
     */
    public function marketList(Request $request): Response
    {
        $page = (int) $request->get('page', 1);
        $pageSize = (int) $request->get('page_size', 20);

        $result = $this->orderService->getMarketList($page, $pageSize);

        return json(['code' => 0, 'msg' => 'success', 'data' => $result]);
    }

    /**
     * 获取订单卖方的收款方式（买家需看到真实账号）
     */
        /**
     * 获取订单卖方的收款方式（买家需看到真实账号）
     * 只返回挂单时选择的收款方式，而非全部
     */
    public function orderPaymentMethods(Request $request): Response
    {
        $orderId = (int) $request->route('id');
        $order = Order::find($orderId);

        if (!$order) {
            return json(['code' => 404, 'msg' => '订单不存在', 'data' => null]);
        }

        if (!$order->merchant_id) {
            return json(['code' => 0, 'msg' => 'success', 'data' => []]);
        }

        // 只查询挂单时选择的收款方式
        $query = PaymentMethod::where('merchant_id', $order->merchant_id);

        if ($order->payment_method_id) {
            $query->where('id', $order->payment_method_id);
        }

        $methods = $query
            ->order('is_default', 'desc')
            ->order('id', 'asc')
            ->select();

        $typeTextMap = [
            'wechat' => '微信',
            'alipay' => '支付宝',
            'bank' => '银行卡',
        ];

        $list = $methods->map(function ($m) use ($typeTextMap) {
            $arr = $m->toArray();
            $arr['type_text'] = $typeTextMap[$m->type] ?? $m->type;
            return $arr;
        });

        return json(['code' => 0, 'msg' => 'success', 'data' => $list]);
    }


    /**
     * 锁定订单（买家点击购买时调用）
     * 20分钟自动过期
     */
    public function lockOrder(Request $request): Response
    {
        $orderId = (int) $request->post('id', 0);
        if (!$orderId) {
            $orderId = (int) $request->post('order_id', 0);
        }
        $userId = $request->uid;

        if (!$orderId) {
            return json(['code' => 400, 'msg' => '缺少订单ID', 'data' => null]);
        }

        $order = Order::find($orderId);
        if (!$order) {
            return json(['code' => 404, 'msg' => '订单不存在', 'data' => null]);
        }

        // 只有出售中的订单可以锁定
        if ($order->status != 1) {
            return json(['code' => 400, 'msg' => '该订单当前状态不可购买', 'data' => null]);
        }

        // 卖家不能购买自己的订单
        if ($order->user_id == $userId) {
            return json(['code' => 400, 'msg' => '不能购买自己的订单', 'data' => null]);
        }

        // 检查是否已被锁定
        $now = time();
        $lockedBy = (int) ($order->locked_by ?? 0);
        $lockedAt = $order->locked_at;

        if ($lockedBy > 0 && $lockedAt) {
            $lockTime = strtotime($lockedAt);
            $expireSeconds = 1200; // 20分钟

            // 锁定未过期
            if ($now - $lockTime < $expireSeconds) {
                if ($lockedBy == $userId) {
                    // 已被自己锁定，直接返回成功
                    return json(['code' => 0, 'msg' => '继续购买', 'data' => ['locked' => true]]);
                } else {
                    // 被他人锁定
                    return json(['code' => 409, 'msg' => '该挂单正在被其他用户购买中', 'data' => null]);
                }
            }
            // 锁定已过期，继续执行锁定逻辑
        }

        // 检查用户是否已有其他未过期的锁定订单（20分钟内）
        $expireTime = date('Y-m-d H:i:s', time() - 1200);
        $existingLock = Db::name('orders')
            ->where('locked_by', $userId)
            ->where('status', 1)
            ->where('is_deleted', 0)
            ->where('id', '<>', $orderId)
            ->where('locked_at', '>', $expireTime)
            ->find();

        if ($existingLock) {
            return json(['code' => 423, 'msg' => '您还有未完成的订单，需完成交易后才可再次下单，您也可以取消放弃订单再重新购买', 'data' => ['locked_order_id' => $existingLock['id']]]);
        }

        // 执行锁定
        $order->locked_by = $userId;
        $order->locked_at = date('Y-m-d H:i:s');
        $order->save();

        return json(['code' => 0, 'msg' => '锁定成功', 'data' => ['locked' => true]]);
    }

    /**
     * 查询订单状态（含锁定状态，前端轮询用）
     */
    public function checkOrderStatus(Request $request): Response
    {
        $orderId = (int) $request->route('id');
        $userId = $request->uid;

        $order = Order::find($orderId);
        if (!$order) {
            return json(['code' => 404, 'msg' => '订单不存在', 'data' => null]);
        }

        $lockedByOther = false;
        $lockedByMe = false;

        $lockedBy = (int) ($order->locked_by ?? 0);
        $lockedAt = $order->locked_at;

        if ($lockedBy > 0 && $lockedAt) {
            $lockTime = strtotime($lockedAt);
            $expireSeconds = 1200; // 20分钟

            if (time() - $lockTime < $expireSeconds) {
                // 锁定有效
                if ($lockedBy == $userId) {
                    $lockedByMe = true;
                } else {
                    $lockedByOther = true;
                }
            }
            // 锁定过期则视为未锁定
        }

        return json([
            'code' => 0,
            'msg' => 'success',
            'data' => [
                'id' => $order->id,
                'status' => $order->status,
                'locked_by_other' => $lockedByOther,
                'locked_by_me' => $lockedByMe,
            ]
        ]);
    }

    /**
     * 解锁订单（买家主动解锁自己锁定的订单）
     */
    public function unlockOrder(Request $request): Response
    {
        $orderId = (int) $request->post('id', 0);
        if (!$orderId) {
            $orderId = (int) $request->post('order_id', 0);
        }
        $userId = $request->uid;

        if (!$orderId) {
            return json(['code' => 400, 'msg' => '缺少订单ID', 'data' => null]);
        }

        $order = Order::find($orderId);
        if (!$order) {
            return json(['code' => 404, 'msg' => '订单不存在', 'data' => null]);
        }

        $lockedBy = (int) ($order->locked_by ?? 0);
        if ($lockedBy !== $userId) {
            return json(['code' => 403, 'msg' => '只能解锁自己锁定的订单', 'data' => null]);
        }

        $order->locked_by = 0;
        $order->locked_at = null;
        $order->save();

        return json(['code' => 0, 'msg' => '已解锁', 'data' => null]);
    }
}
