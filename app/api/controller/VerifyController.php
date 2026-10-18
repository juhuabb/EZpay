<?php
declare(strict_types=1);
namespace app\api\controller;

use app\model\User;
use app\model\UserVerify;
use app\model\Merchant;
use app\service\RsaService;
use think\Request;
use think\Response;

class VerifyController
{
    /**
     * 提交实名认证
     */
    public function submitRealName(Request $request): Response
    {
        $data = $request->post();
        $user = User::find($request->uid);

        if ($user->real_name_verified) {
            return json(['code' => 400, 'msg' => '已完成实名认证', 'data' => null]);
        }

        // 检查是否有待审核的认证
        $existing = UserVerify::where('user_id', $user->id)->where('status', 0)->find();
        if ($existing) {
            return json(['code' => 400, 'msg' => '已有待审核的认证申请', 'data' => null]);
        }

        if (empty($data['real_name']) || empty($data['id_card']) || empty($data['id_card_front']) || empty($data['id_card_back'])) {
            return json(['code' => 400, 'msg' => '请填写完整的认证信息', 'data' => null]);
        }

        // 身份证号格式验证
        if (!preg_match('/^\d{17}[\dXx]$/', $data['id_card'])) {
            return json(['code' => 400, 'msg' => '身份证号格式不正确', 'data' => null]);
        }

        UserVerify::create([
            'user_id' => $user->id,
            'real_name' => $data['real_name'],
            'id_card' => $data['id_card'],
            'id_card_front' => $data['id_card_front'],
            'id_card_back' => $data['id_card_back'],
            'status' => 0,
        ]);

        // 更新用户状态为审核中
        $user->status = 2;
        $user->save();

        return json(['code' => 0, 'msg' => '实名认证申请已提交', 'data' => null]);
    }

    /**
     * 查询实名认证状态
     */
    public function verifyStatus(Request $request): Response
    {
        $user = User::find($request->uid);
        $verify = UserVerify::where('user_id', $user->id)
            ->order('id', 'desc')
            ->find();

        return json([
            'code' => 0,
            'msg' => 'success',
            'data' => [
                'real_name_verified' => $user->real_name_verified,
                'verify_status' => $verify ? $verify->status : -1,
                'verify_status_text' => $verify ? ($verify->status_text) : '未提交',
            ]
        ]);
    }

    /**
     * 提交商户认证
     */
    public function submitMerchant(Request $request): Response
    {
        $user = User::find($request->uid);

        if (!$user->real_name_verified) {
            return json(['code' => 400, 'msg' => '请先完成实名认证', 'data' => null]);
        }

        if ($user->merchant_verified) {
            return json(['code' => 400, 'msg' => '已是认证商户', 'data' => null]);
        }

        $data = $request->post();

        // 检查是否已有商户记录
        $existing = Merchant::where('user_id', $user->id)->find();
        if ($existing && $existing->status === 2) {
            return json(['code' => 400, 'msg' => '商户认证正在审核中', 'data' => null]);
        }

        // 验证服务器IP（支持逗号分隔多个IP）
        $serverIp = trim($data['server_ip'] ?? '');
        if (empty($serverIp)) {
            return json(['code' => 400, 'msg' => '请填写服务器IP', 'data' => null]);
        }
        $ipList = array_filter(array_map('trim', explode(',', $serverIp)), function($ip) {
            return !empty($ip);
        });
        foreach ($ipList as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP)) {
                return json(['code' => 400, 'msg' => '服务器IP格式不正确: ' . $ip, 'data' => null]);
            }
        }

        $merchantData = [
            'user_id' => $user->id,
            'company_name' => trim($data['name'] ?? ''),
            'website_url' => $data['website_url'] ?? '',
            'server_ip' => $serverIp,
            'status' => 2, // 审核中
        ];

        if ($existing) {
            $existing->save($merchantData);
            $merchant = $existing;
        } else {
            $merchant = Merchant::create($merchantData);
        }

        return json(['code' => 0, 'msg' => '商户认证申请已提交', 'data' => null]);
    }

    /**
     * 查询商户认证状态
     */
    public function merchantStatus(Request $request): Response
    {
        $user = User::find($request->uid);
        $merchant = Merchant::where('user_id', $user->id)->find();

        return json([
            'code' => 0,
            'msg' => 'success',
            'data' => [
                'merchant_verified' => $user->merchant_verified,
                'status' => $merchant ? $merchant->status : -1,
                'status_text' => $merchant ? match ($merchant->status) {
                    0 => '已禁用',
                    1 => '正常',
                    2 => '审核中',
                    default => '未知',
                } : '未申请',
            ]
        ]);
    }
}
