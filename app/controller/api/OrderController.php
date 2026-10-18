<?php
declare(strict_types=1);

namespace app\controller\api;

use app\model\Order;
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
        $orderId = (int) $request->route('id');

        try {
            $this->orderService->deleteOrder($orderId, $request->uid);

            return json(['code' => 0, 'msg' => '挂单已下架', 'data' => null]);
        } catch (ValidateException $e) {
            return json(['code' => 400, 'msg' => $e->getMessage(), 'data' => null]);
        }
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
     * 挂单详情
     */
    public function orderDetail(Request $request): Response
    {
        $orderId = (int) $request->route('id');
        $order = Order::find($orderId);

        if (!$order) {
            return json(['code' => 404, 'msg' => '挂单不存在', 'data' => null]);
        }

        return json(['code' => 0, 'msg' => 'success', 'data' => $order]);
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
}
