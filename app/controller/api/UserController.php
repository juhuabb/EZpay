<?php
declare(strict_types=1);

namespace app\controller\api;

use app\model\User;
use think\Request;
use think\Response;

class UserController
{
    /**
     * 获取个人资料
     */
    public function profile(Request $request): Response
    {
        $user = User::find($request->uid);
        if (!$user) {
            return json(['code' => 404, 'msg' => '用户不存在', 'data' => null]);
        }

        $data = [
            'id' => $user['id'],
            'username' => $user['username'],
            'phone' => $user['phone'],
            'role' => $user['role'],
            'balance' => $user['balance'],
            'principal' => $user['principal'],
            'bonus' => $user['bonus'],
            'real_name_verified' => $user['real_name_verified'],
            'merchant_verified' => $user['merchant_verified'],
            'status' => $user['status'],
            'created_at' => $user['created_at'],
        ];

        return json(['code' => 0, 'msg' => 'success', 'data' => $data]);
    }

    /**
     * 更新个人资料
     */
    public function updateProfile(Request $request): Response
    {
        $user = User::find($request->uid);
        $data = $request->only(['username'], 'post');

        if (!empty($data['username'])) {
            if (!preg_match('/^[a-zA-Z0-9_]{3,20}$/', $data['username'])) {
                return json(['code' => 400, 'msg' => '用户名格式不正确', 'data' => null]);
            }
            $exists = User::where('username', $data['username'])
                ->where('id', '<>', $user->id)
                ->find();
            if ($exists) {
                return json(['code' => 400, 'msg' => '用户名已被使用', 'data' => null]);
            }
            $user->username = $data['username'];
        }

        $user->save();

        return json(['code' => 0, 'msg' => '更新成功', 'data' => [
            'id' => $user['id'],
            'username' => $user['username'],
            'phone' => $user['phone'],
        ]]);
    }

    /**
     * 修改登录密码
     */
    public function changePassword(Request $request): Response
    {
        $data = $request->post();
        $user = User::find($request->uid);

        if (empty($data['old_password']) || empty($data['new_password'])) {
            return json(['code' => 400, 'msg' => '请输入原密码和新密码', 'data' => null]);
        }

        if (!$user->verifyPassword($data['old_password'])) {
            return json(['code' => 400, 'msg' => '原密码错误', 'data' => null]);
        }

        if (strlen($data['new_password']) < 8) {
            return json(['code' => 400, 'msg' => '新密码长度不能少于8位', 'data' => null]);
        }

        $user->password_hash = password_hash($data['new_password'], PASSWORD_BCRYPT);
        $user->save();

        return json(['code' => 0, 'msg' => '密码修改成功', 'data' => null]);
    }

    /**
     * 修改支付密码
     */
    public function changePayPassword(Request $request): Response
    {
        $data = $request->post();
        $user = User::find($request->uid);

        if (empty($data['pay_password']) || strlen((string)$data['pay_password']) !== 6) {
            return json(['code' => 400, 'msg' => '支付密码必须为6位数字', 'data' => null]);
        }

        if (!preg_match('/^\d{6}$/', (string)$data['pay_password'])) {
            return json(['code' => 400, 'msg' => '支付密码必须为纯数字', 'data' => null]);
        }

        if (!empty($user->login_password)) {
            if (empty($data['old_pay_password'])) {
                return json(['code' => 400, 'msg' => '请输入原支付密码', 'data' => null]);
            }
            if (!$user->verifyPayPassword((string)$data['old_pay_password'])) {
                return json(['code' => 400, 'msg' => '原支付密码错误', 'data' => null]);
            }
        }

        $user->setPayPassword((string)$data['pay_password']);
        $user->save();

        return json(['code' => 0, 'msg' => '支付密码设置成功', 'data' => null]);
    }

    /**
     * 获取余额信息
     */
    public function balance(Request $request): Response
    {
        $user = User::find($request->uid);

        return json([
            'code' => 0,
            'msg' => 'success',
            'data' => [
                'balance' => $user->balance,
                'principal' => $user->principal,
                'bonus' => $user->bonus,
            ]
        ]);
    }

    /**
     * 余额变动日志
     */
    public function balanceLog(Request $request): Response
    {
        $userId = $request->uid;
        $page = (int) $request->get('page', 1);
        $pageSize = (int) $request->get('page_size', 20);

        $transactions = \app\model\Transaction::where('buyer_id', $userId)
            ->whereOr('seller_id', $userId)
            ->order('id', 'desc')
            ->page($page, $pageSize)
            ->select();

        $total = \app\model\Transaction::where('buyer_id', $userId)
            ->whereOr('seller_id', $userId)
            ->count();

        return json([
            'code' => 0,
            'msg' => 'success',
            'data' => [
                'list' => $transactions,
                'total' => $total,
            ]
        ]);
    }
}
