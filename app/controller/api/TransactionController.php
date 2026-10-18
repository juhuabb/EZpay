<?php
declare(strict_types=1);

namespace app\controller\api;

use app\model\Transaction;
use app\service\TransactionService;
use think\Request;
use think\Response;
use think\exception\ValidateException;

class TransactionController
{
    protected TransactionService $transactionService;

    public function __construct(TransactionService $transactionService)
    {
        $this->transactionService = $transactionService;
    }

    /**
     * 购买挂单(创建交易)
     */
    public function createTransaction(Request $request): Response
    {
        $data = $request->post();

        if (empty($data['order_id']) || empty($data['points_amount'])) {
            return json(['code' => 400, 'msg' => '缺少必要参数', 'data' => null]);
        }

        try {
            $transaction = $this->transactionService->createTransaction(
                $request->uid,
                (int) $data['order_id'],
                (float) $data['points_amount'],
                $data['payment_method'] ?? 'balance'
            );

            return json([
                'code' => 0,
                'msg' => '购买成功',
                'data' => $transaction
            ]);
        } catch (ValidateException $e) {
            return json(['code' => 400, 'msg' => $e->getMessage(), 'data' => null]);
        }
    }

    /**
     * 我的交易列表
     */
    public function myTransactions(Request $request): Response
    {
        $page = (int) $request->get('page', 1);
        $pageSize = (int) $request->get('page_size', 20);
        $type = $request->get('type', 'all'); // all/buy/sell

        $userId = $request->uid;
        $query = Transaction::with(['order']);

        if ($type === 'buy') {
            $query->where('buyer_id', $userId);
        } elseif ($type === 'sell') {
            $query->where('seller_id', $userId);
        } else {
            $query->where(function ($q) use ($userId) {
                $q->where('buyer_id', $userId)->whereOr('seller_id', $userId);
            });
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
            ]
        ]);
    }

    /**
     * 交易详情
     */
    public function transactionDetail(Request $request): Response
    {
        $id = (int) $request->route('id');
        $transaction = Transaction::with(['order', 'buyer:id,username', 'seller:id,username'])->find($id);

        if (!$transaction) {
            return json(['code' => 404, 'msg' => '交易不存在', 'data' => null]);
        }

        // 只能查看自己的交易
        if ($transaction->buyer_id !== $request->uid && $transaction->seller_id !== $request->uid) {
            return json(['code' => 403, 'msg' => '无权查看', 'data' => null]);
        }

        return json(['code' => 0, 'msg' => 'success', 'data' => $transaction]);
    }
}
