<?php
declare(strict_types=1);
namespace app\api\controller;

use think\Request;
use think\Response;
use think\facade\Db;

class PaymentMethodController
{
    /**
     * 获取当前用户的收款方式列表
     */
    public function myMethods(Request $request): Response
    {
        $userId = $request->uid;

        $list = Db::name('payment_methods')
            ->where('merchant_id', $userId)
            ->order('is_default desc, id asc')
            ->select()
            ->toArray();

        // 给类型加中文标签
        $typeMap = [
            'bank'  => '银行卡',
            'alipay'=> '支付宝',
            'wechat'=> '微信',
            'qq'    => 'QQ',
            'dcep'  => '数字人民币',
        ];
        foreach ($list as &$item) {
            $item['type_text'] = $typeMap[$item['type']] ?? $item['type'];
            // 隐藏部分账号
            $item['account_no_masked'] = $this->maskAccount($item['account_no'] ?? '');
        }

        return json(['code' => 0, 'msg' => 'success', 'data' => $list]);
    }

    private function maskAccount(string $account): string
    {
        $len = mb_strlen($account);
        if ($len <= 4) return $account;
        return str_repeat('*', $len - 4) . mb_substr($account, -4);
    }
}
