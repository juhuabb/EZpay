<?php
declare(strict_types=1);

namespace app\controller\admin;

use think\facade\Db;
use think\facade\Request;

class MerchantManage
{
    /**
     * 商户列表
     */
    public function list()
    {
        $page = (int)Request::param('page', 1);
        $limit = (int)Request::param('limit', 10);
        $status = Request::param('status', '');

        $query = Db::name('merchants');
        if ($status !== '' && $status !== null) {
            $query->where('status', (int)$status);
        }

        try {
            $total = $query->count();
            $list = $query->order('id', 'desc')->page($page, $limit)->select()->toArray();
        } catch (\Exception $e) {
            $list = [];
            $total = 0;
        }

        return json(['code' => 0, 'data' => ['list' => $list, 'total' => $total]]);
    }

    /**
     * 商户详情
     */
    public function detail()
    {
        $id = (int)Request::param('id', 0);
        if (!$id) {
            return json(['code' => 1, 'msg' => '参数错误']);
        }
        $merchant = Db::name('merchants')->where('id', $id)->find();
        if (!$merchant) {
            return json(['code' => 1, 'msg' => '商户不存在']);
        }
        $user = Db::name('users')->where('id', $merchant['user_id'])->find();
        $merchant['user'] = $user ? ['id' => $user['id'], 'username' => $user['username'], 'nickname' => $user['nickname'] ?? ''] : null;
        return json(['code' => 0, 'data' => $merchant]);
    }

    /**
     * 开启/关闭支付功能
     */
    public function togglePayment()
    {
        $id = (int)Request::param('id', 0);
        if (!$id) {
            return json(['code' => 1, 'msg' => '参数错误']);
        }
        $merchant = Db::name('merchants')->where('id', $id)->find();
        if (!$merchant) {
            return json(['code' => 1, 'msg' => '商户不存在']);
        }
        $newVal = ($merchant['payment_enabled'] ?? 0) == 1 ? 0 : 1;
        Db::name('merchants')->where('id', $id)->update([
            'payment_enabled' => $newVal,
            'updated_at' => date('Y-m-d H:i:s')
        ]);
        return json(['code' => 0, 'msg' => $newVal == 1 ? '支付功能已开启' : '支付功能已关闭']);
    }

    /**
     * 设置支付限额
     */
    public function setPaymentLimit()
    {
        $id = (int)Request::param('id', 0);
        if (!$id) {
            return json(['code' => 1, 'msg' => '参数错误']);
        }
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true);
        if (!$data) {
            $data = Request::post();
        }
        $limit = (float)($data['payment_limit'] ?? 0);
        $merchant = Db::name('merchants')->where('id', $id)->find();
        if (!$merchant) {
            return json(['code' => 1, 'msg' => '商户不存在']);
        }
        Db::name('merchants')->where('id', $id)->update([
            'payment_limit' => $limit,
            'updated_at' => date('Y-m-d H:i:s')
        ]);
        return json(['code' => 0, 'msg' => '支付限额已设置']);
    }

    /**
     * 重置商户API密钥
     */
    public function resetApiKeys()
    {
        $id = (int)Request::param('id', 0);
        if (!$id) {
            return json(['code' => 1, 'msg' => '参数错误']);
        }
        $merchant = Db::name('merchants')->where('id', $id)->find();
        if (!$merchant) {
            return json(['code' => 1, 'msg' => '商户不存在']);
        }
        $newApiKey = 'mk_' . bin2hex(random_bytes(16));
        $newApiSecret = bin2hex(random_bytes(32));
        Db::name('merchants')->where('id', $id)->update([
            'api_key' => $newApiKey,
            'api_secret' => $newApiSecret,
            'updated_at' => date('Y-m-d H:i:s')
        ]);
        return json(['code' => 0, 'msg' => '密钥已重置', 'data' => [
            'api_key' => $newApiKey,
            'api_secret' => $newApiSecret
        ]]);
    }
}
