<?php
declare(strict_types=1);

namespace app\controller\api;

use app\model\PaymentMethod;
use app\model\Merchant;
use think\Request;
use think\Response;

class PaymentController
{
    /**
     * 添加收款方式
     */
    public function addPaymentMethod(Request $request): Response
    {
        $user = \app\model\User::find($request->uid);
        if (!$user->isMerchant()) {
            return json(['code' => 403, 'msg' => '需要商户身份', 'data' => null]);
        }

        $merchant = Merchant::where('user_id', $user->id)->find();
        if (!$merchant) {
            return json(['code' => 400, 'msg' => '商户信息异常', 'data' => null]);
        }

        $data = $request->post();
        $allowedTypes = ['bank', 'alipay', 'wechat'];

        if (empty($data['type']) || !in_array($data['type'], $allowedTypes)) {
            return json(['code' => 400, 'msg' => '无效的收款方式类型', 'data' => null]);
        }

        if (empty($data['account_name']) || empty($data['account_no'])) {
            return json(['code' => 400, 'msg' => '请填写完整收款信息', 'data' => null]);
        }

        // 如果设为默认，先取消其他默认
        $isDefault = !empty($data['is_default']) ? 1 : 0;
        if ($isDefault) {
            PaymentMethod::where('merchant_id', $merchant->id)
                ->update(['is_default' => 0]);
        }

        $method = PaymentMethod::create([
            'merchant_id' => $merchant->id,
            'type' => $data['type'],
            'account_name' => $data['account_name'],
            'account_no' => $data['account_no'],
            'qrcode_url' => $data['qrcode_url'] ?? '',
            'is_default' => $isDefault,
        ]);

        return json(['code' => 0, 'msg' => '添加成功', 'data' => $method]);
    }

    /**
     * 更新收款方式
     */
    public function updatePaymentMethod(Request $request): Response
    {
        $id = (int) $request->route('id');
        $user = \app\model\User::find($request->uid);
        $merchant = Merchant::where('user_id', $user->id)->find();

        $method = PaymentMethod::where('id', $id)
            ->where('merchant_id', $merchant->id)
            ->find();

        if (!$method) {
            return json(['code' => 404, 'msg' => '收款方式不存在', 'data' => null]);
        }

        $data = $request->post();

        $updateData = [];
        if (!empty($data['account_name'])) $updateData['account_name'] = $data['account_name'];
        if (!empty($data['account_no'])) $updateData['account_no'] = $data['account_no'];
        if (!empty($data['qrcode_url'])) $updateData['qrcode_url'] = $data['qrcode_url'];

        if (isset($data['is_default'])) {
            $updateData['is_default'] = $data['is_default'] ? 1 : 0;
            if ($updateData['is_default']) {
                PaymentMethod::where('merchant_id', $merchant->id)
                    ->where('id', '<>', $id)
                    ->update(['is_default' => 0]);
            }
        }

        $method->save($updateData);

        return json(['code' => 0, 'msg' => '更新成功', 'data' => $method]);
    }

    /**
     * 删除收款方式
     */
    public function deletePaymentMethod(Request $request): Response
    {
        $id = (int) $request->route('id');
        $user = \app\model\User::find($request->uid);
        $merchant = Merchant::where('user_id', $user->id)->find();

        $method = PaymentMethod::where('id', $id)
            ->where('merchant_id', $merchant->id)
            ->find();

        if (!$method) {
            return json(['code' => 404, 'msg' => '收款方式不存在', 'data' => null]);
        }

        $method->delete();

        return json(['code' => 0, 'msg' => '删除成功', 'data' => null]);
    }

    /**
     * 收款方式列表
     */
    public function listPaymentMethods(Request $request): Response
    {
        $user = \app\model\User::find($request->uid);
        $merchant = Merchant::where('user_id', $user->id)->find();

        if (!$merchant) {
            return json(['code' => 400, 'msg' => '非商户用户', 'data' => null]);
        }

        $list = PaymentMethod::where('merchant_id', $merchant->id)
            ->order('is_default', 'desc')
            ->order('id', 'desc')
            ->select();

        return json(['code' => 0, 'msg' => 'success', 'data' => $list]);
    }
}
