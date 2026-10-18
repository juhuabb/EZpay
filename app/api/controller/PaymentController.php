<?php
declare(strict_types=1);

namespace app\api\controller;

use app\model\PaymentMethod;
use think\Request;
use think\Response;

class PaymentController
{
    /**
     * 添加收款方式
     * 支持类型：bank/alipay/wechat/qq/dcep
     */
    public function addPaymentMethod(Request $request): Response
    {
        $userId = $request->uid;
        $data = $request->post();
        $allowedTypes = ['bank', 'alipay', 'wechat', 'qq', 'dcep'];

        if (empty($data['type']) || !in_array($data['type'], $allowedTypes)) {
            return json(['code' => 400, 'msg' => '无效的收款方式类型', 'data' => null]);
        }

        if (empty($data['account_name'])) {
            return json(['code' => 400, 'msg' => '请填写姓名', 'data' => null]);
        }

        // 按类型验证
        $createData = [
            'merchant_id' => $userId,  // merchant_id 实际存储用户 ID
            'type' => $data['type'],
            'account_name' => $data['account_name'],
            'is_default' => !empty($data['is_default']) ? 1 : 0,
        ];

        switch ($data['type']) {
            case 'bank':
            case 'alipay':
            case 'wechat':
                // 传统类型：姓名 + 账号 + 收款码（可选）
                if (empty($data['account_no'])) {
                    return json(['code' => 400, 'msg' => '请填写账号', 'data' => null]);
                }
                $createData['account_no'] = $data['account_no'];
                $createData['qrcode_url'] = $data['qrcode_url'] ?? '';
                break;

            case 'qq':
                // QQ收款：姓名 + QQ号（5-11位数字）+ QQ收款码（必填）
                if (empty($data['qq_no']) || !preg_match('/^\d{5,11}$/', $data['qq_no'])) {
                    return json(['code' => 400, 'msg' => 'QQ号必须是5-11位纯数字', 'data' => null]);
                }
                if (empty($data['qrcode_url'])) {
                    return json(['code' => 400, 'msg' => '请上传QQ收款码', 'data' => null]);
                }
                // 验证图片URL是否合法
                if (!$this->validateImageUrl($data['qrcode_url'])) {
                    return json(['code' => 400, 'msg' => 'QQ收款码图片格式不正确', 'data' => null]);
                }
                $createData['account_no'] = $data['qq_no'];
                $createData['qrcode_url'] = $data['qrcode_url'];
                break;

            case 'dcep':
                // 数字人民币：姓名 + 钱包编号（纯数字）+ 编码图 + 实名图
                if (empty($data['wallet_no']) || !preg_match('/^\d+$/', $data['wallet_no'])) {
                    return json(['code' => 400, 'msg' => '钱包编号必须是纯数字', 'data' => null]);
                }
                if (empty($data['qrcode_url'])) {
                    return json(['code' => 400, 'msg' => '请上传编码图', 'data' => null]);
                }
                if (!$this->validateImageUrl($data['qrcode_url'])) {
                    return json(['code' => 400, 'msg' => '编码图图片格式不正确', 'data' => null]);
                }
                if (empty($data['id_card_image'])) {
                    return json(['code' => 400, 'msg' => '请上传实名图', 'data' => null]);
                }
                if (!$this->validateImageUrl($data['id_card_image'])) {
                    return json(['code' => 400, 'msg' => '实名图图片格式不正确', 'data' => null]);
                }
                $createData['account_no'] = $data['wallet_no'];
                $createData['qrcode_url'] = $data['qrcode_url'];
                $createData['id_card_image'] = $data['id_card_image'];
                break;
        }

        // 如果设为默认，先取消其他默认
        if ($createData['is_default']) {
            PaymentMethod::where('merchant_id', $userId)
                ->update(['is_default' => 0]);
        }

        $method = PaymentMethod::create($createData);

        return json(['code' => 0, 'msg' => '添加成功', 'data' => $method]);
    }

    /**
     * 更新收款方式
     */
    public function updatePaymentMethod(Request $request): Response
    {
        $id = (int) $request->route('id');
        $userId = $request->uid;

        $method = PaymentMethod::where('id', $id)
            ->where('merchant_id', $userId)
            ->find();

        if (!$method) {
            return json(['code' => 404, 'msg' => '收款方式不存在', 'data' => null]);
        }

        $data = $request->post();
        $updateData = [];

        if (!empty($data['account_name'])) {
            $updateData['account_name'] = $data['account_name'];
        }

        // 按类型更新
        switch ($method->type) {
            case 'bank':
            case 'alipay':
            case 'wechat':
                if (!empty($data['account_no'])) $updateData['account_no'] = $data['account_no'];
                if (!empty($data['qrcode_url'])) {
                    if (!$this->validateImageUrl($data['qrcode_url'])) {
                        return json(['code' => 400, 'msg' => '收款码图片格式不正确', 'data' => null]);
                    }
                    $updateData['qrcode_url'] = $data['qrcode_url'];
                }
                break;

            case 'qq':
                if (!empty($data['qq_no'])) {
                    if (!preg_match('/^\d{5,11}$/', $data['qq_no'])) {
                        return json(['code' => 400, 'msg' => 'QQ号必须是5-11位纯数字', 'data' => null]);
                    }
                    $updateData['account_no'] = $data['qq_no'];
                }
                if (!empty($data['qrcode_url'])) {
                    if (!$this->validateImageUrl($data['qrcode_url'])) {
                        return json(['code' => 400, 'msg' => 'QQ收款码图片格式不正确', 'data' => null]);
                    }
                    $updateData['qrcode_url'] = $data['qrcode_url'];
                }
                break;

            case 'dcep':
                if (!empty($data['wallet_no'])) {
                    if (!preg_match('/^\d+$/', $data['wallet_no'])) {
                        return json(['code' => 400, 'msg' => '钱包编号必须是纯数字', 'data' => null]);
                    }
                    $updateData['account_no'] = $data['wallet_no'];
                }
                if (!empty($data['qrcode_url'])) {
                    if (!$this->validateImageUrl($data['qrcode_url'])) {
                        return json(['code' => 400, 'msg' => '编码图图片格式不正确', 'data' => null]);
                    }
                    $updateData['qrcode_url'] = $data['qrcode_url'];
                }
                if (!empty($data['id_card_image'])) {
                    if (!$this->validateImageUrl($data['id_card_image'])) {
                        return json(['code' => 400, 'msg' => '实名图图片格式不正确', 'data' => null]);
                    }
                    $updateData['id_card_image'] = $data['id_card_image'];
                }
                break;
        }

        if (isset($data['is_default'])) {
            $updateData['is_default'] = $data['is_default'] ? 1 : 0;
            if ($updateData['is_default']) {
                PaymentMethod::where('merchant_id', $userId)
                    ->where('id', '<>', $id)
                    ->update(['is_default' => 0]);
            }
        }

        if (!empty($updateData)) {
            $method->save($updateData);
        }

        return json(['code' => 0, 'msg' => '更新成功', 'data' => $method]);
    }

    /**
     * 删除收款方式
     */
    public function deletePaymentMethod(Request $request): Response
    {
        $id = (int) $request->route('id');
        $userId = $request->uid;

        $method = PaymentMethod::where('id', $id)
            ->where('merchant_id', $userId)
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
        $userId = $request->uid;

        $list = PaymentMethod::where('merchant_id', $userId)
            ->order('is_default', 'desc')
            ->order('id', 'desc')
            ->select();

        return json(['code' => 0, 'msg' => 'success', 'data' => $list]);
    }

    /**
     * 验证图片URL是否合法
     */
    private function validateImageUrl(string $url): bool
    {
        if (empty($url)) return false;
        
        // 必须是 http/https 开头
        if (!preg_match('/^https?:\/\//', $url)) return false;
        
        // 必须是图片扩展名
        if (!preg_match('/\.(jpg|jpeg|png|gif|webp)$/i', parse_url($url, PHP_URL_PATH))) {
            return false;
        }
        
        // 不能包含危险字符
        if (preg_match('/[<>"\']/', $url)) return false;
        
        return true;
    }
}
