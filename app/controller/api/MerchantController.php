<?php
declare(strict_types=1);

namespace app\controller\api;

use app\model\Merchant;
use app\model\Order;
use app\model\Transaction;
use app\model\User;
use app\service\RsaService;
use think\Request;
use think\Response;

class MerchantController
{
    /**
     * 商户仪表盘数据
     */
    public function merchantDashboard(Request $request): Response
    {
        $user = User::find($request->uid);
        $merchant = Merchant::where('user_id', $user->id)->find();

        if (!$merchant) {
            return json(['code' => 400, 'msg' => '非商户用户', 'data' => null]);
        }

        $totalOrders = Order::where('merchant_id', $merchant->id)->count();
        $activeOrders = Order::where('merchant_id', $merchant->id)->where('status', 1)->count();
        $totalTransactions = Transaction::where('merchant_id', $merchant->id)->where('status', 2)->count();
        $totalVolume = Transaction::where('merchant_id', $merchant->id)
            ->where('status', 2)
            ->sum('total_price');
        $totalPoints = Transaction::where('merchant_id', $merchant->id)
            ->where('status', 2)
            ->sum('points_amount');

        // 今日数据
        $today = date('Y-m-d');
        $todayTransactions = Transaction::where('merchant_id', $merchant->id)
            ->where('status', 2)
            ->whereDay('created_at', $today)
            ->count();
        $todayVolume = Transaction::where('merchant_id', $merchant->id)
            ->where('status', 2)
            ->whereDay('created_at', $today)
            ->sum('total_price');

        return json([
            'code' => 0,
            'msg' => 'success',
            'data' => [
                'total_orders' => $totalOrders,
                'active_orders' => $activeOrders,
                'total_transactions' => $totalTransactions,
                'total_volume' => (float) $totalVolume,
                'total_points' => (float) $totalPoints,
                'today_transactions' => $todayTransactions,
                'today_volume' => (float) $todayVolume,
            ]
        ]);
    }

    /**
     * 获取API凭证
     */
    public function apiCredentials(Request $request): Response
    {
        $user = User::find($request->uid);
        $merchant = Merchant::where('user_id', $user->id)->find();

        if (!$merchant) {
            return json(['code' => 400, 'msg' => '非商户用户', 'data' => null]);
        }

        return json([
            'code' => 0,
            'msg' => 'success',
            'data' => [
                'api_key' => $merchant->api_key,
                'api_secret' => '******（仅生成时可见）',
                'rsa_public_key' => $merchant->rsa_public_key,
                'payment_enabled' => $merchant->payment_enabled,
                'payment_limit' => $merchant->payment_limit,
                'has_totp' => !empty($merchant->totp_secret),
                'has_rsa' => !empty($merchant->rsa_public_key),
            ]
        ]);
    }

    /**
     * 重置API密钥
     */
    public function resetApiKeys(Request $request): Response
    {
        $user = User::find($request->uid);
        $merchant = Merchant::where('user_id', $user->id)->find();

        if (!$merchant) {
            return json(['code' => 400, 'msg' => '非商户用户', 'data' => null]);
        }

        $merchant->generateApiCredentials();
        $merchant->save();

        return json([
            'code' => 0,
            'msg' => 'API密钥已重置',
            'data' => [
                'api_key' => $merchant->api_key,
                'api_secret' => $merchant->api_secret,
            ]
        ]);
    }

    /**
     * 重置RSA密钥对
     */
    public function resetRsaKeys(Request $request): Response
    {
        $user = User::find($request->uid);
        $merchant = Merchant::where('user_id', $user->id)->find();

        if (!$merchant) {
            return json(['code' => 400, 'msg' => '非商户用户', 'data' => null]);
        }

        $rsaService = new RsaService();
        $keyPair = $rsaService->generateKeyPair();

        $merchant->rsa_private_key = $keyPair['private_key'];
        $merchant->rsa_public_key = $keyPair['public_key'];
        $merchant->save();

        return json([
            'code' => 0,
            'msg' => 'RSA密钥对已重置',
            'data' => [
                'rsa_private_key' => $keyPair['private_key'],
                'rsa_public_key' => $keyPair['public_key'],
            ]
        ]);
    }

    /**
     * 商户交易列表
     */
    public function merchantTransactions(Request $request): Response
    {
        $user = User::find($request->uid);
        $merchant = Merchant::where('user_id', $user->id)->find();

        if (!$merchant) {
            return json(['code' => 400, 'msg' => '非商户用户', 'data' => null]);
        }

        $page = (int) $request->get('page', 1);
        $pageSize = (int) $request->get('page_size', 20);

        $list = Transaction::where('merchant_id', $merchant->id)
            ->with(['buyer:id,username', 'order:id,title'])
            ->order('id', 'desc')
            ->page($page, $pageSize)
            ->select();

        $total = Transaction::where('merchant_id', $merchant->id)->count();

        return json([
            'code' => 0,
            'msg' => 'success',
            'data' => [
                'list' => $list,
                'total' => $total,
            ]
        ]);
    }
}
