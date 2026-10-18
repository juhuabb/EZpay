<?php
declare(strict_types=1);

namespace app\api\controller;

use app\BaseController;
use app\model\User;
use think\facade\Db;
use think\Request;

/**
 * 实名认证控制器
 */
class RealNameController extends BaseController
{
    /**
     * 提交实名认证申请
     * POST /api/realname/submit
     * 参数: real_name, id_card_number
     */
    public function submit(Request $request)
    {
        $userId = $request->uid ?? null;
        if (!$userId) {
            return json(['code' => 401, 'msg' => '未登录']);
        }

        $realName = trim($request->post('real_name', ''));
        $idCardNumber = trim($request->post('id_card_number', ''));

        // 参数校验
        if (empty($realName)) {
            return json(['code' => 1, 'msg' => '请输入真实姓名']);
        }
        if (empty($idCardNumber)) {
            return json(['code' => 1, 'msg' => '请输入身份证号']);
        }

        // 姓名长度校验
        if (mb_strlen($realName) < 2 || mb_strlen($realName) > 30) {
            return json(['code' => 1, 'msg' => '姓名长度应在2-30个字符之间']);
        }

        // 身份证号格式校验（18位）
        if (!preg_match('/^\d{17}[\dXx]$/', $idCardNumber)) {
            return json(['code' => 1, 'msg' => '身份证号格式不正确，请输入18位身份证号']);
        }

        // 查询用户
        $user = Db::name('users')->where('id', $userId)->find();
        if (!$user) {
            return json(['code' => 1, 'msg' => '用户不存在']);
        }

        // 如果已经是已认证状态，不允许重复提交
        if ((int)($user['real_name_verified'] ?? 0) === 1) {
            return json(['code' => 1, 'msg' => '您已通过实名认证，无需重复提交']);
        }

        // 如果正在审核中，不允许重复提交
        if ((int)($user['real_name_verified'] ?? 0) === 2) {
            return json(['code' => 1, 'msg' => '您的实名认证正在审核中，请耐心等待']);
        }

        // 更新用户信息
        $updateData = [
            'real_name' => $realName,
            'id_card_number' => $idCardNumber,
            'real_name_verified' => 2, // 审核中
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        $result = Db::name('users')->where('id', $userId)->update($updateData);

        if ($result !== false) {
            return json([
                'code' => 0,
                'msg' => '实名认证申请已提交，请等待管理员审核',
                'data' => [
                    'real_name' => $realName,
                    'real_name_verified' => 2,
                    'status_text' => '审核中',
                ]
            ]);
        }

        return json(['code' => 1, 'msg' => '提交失败，请稍后重试']);
    }

    /**
     * 查询实名认证状态
     * GET /api/realname/status
     */
    public function status(Request $request)
    {
        $userId = $request->uid ?? null;
        if (!$userId) {
            return json(['code' => 401, 'msg' => '未登录']);
        }

        $user = Db::name('users')->where('id', $userId)->find();
        if (!$user) {
            return json(['code' => 1, 'msg' => '用户不存在']);
        }

        $verifiedStatus = (int)($user['real_name_verified'] ?? 0);
        $statusMap = [
            0 => '未认证',
            1 => '已认证',
            2 => '审核中',
            3 => '审核拒绝',
        ];

        $data = [
            'real_name_verified' => $verifiedStatus,
            'status_text' => $statusMap[$verifiedStatus] ?? '未知',
            'real_name' => null,
            'id_card_number' => null,
        ];

        // 如果已提交过信息，返回脱敏数据
        if (!empty($user['real_name'])) {
            // 姓名脱敏：只显示姓
            $name = $user['real_name'];
            if (mb_strlen($name) > 1) {
                $data['real_name'] = mb_substr($name, 0, 1) . str_repeat('*', mb_strlen($name) - 1);
            } else {
                $data['real_name'] = $name;
            }
        }

        if (!empty($user['id_card_number'])) {
            // 身份证脱敏：显示前4位和后4位
            $idCard = $user['id_card_number'];
            if (strlen($idCard) > 8) {
                $data['id_card_number'] = substr($idCard, 0, 4) . '**********' . substr($idCard, -4);
            } else {
                $data['id_card_number'] = '**********';
            }
        }

        return json(['code' => 0, 'data' => $data]);
    }

    // ========== 管理员接口 ==========

    /**
     * 管理员：获取待审核实名认证列表
     * GET /api/admin/realname/pending
     * 需要管理员权限
     */
    public function adminPending(Request $request)
    {
        $userId = $request->uid ?? null;
        if (!$userId) {
            return json(['code' => 401, 'msg' => '未登录']);
        }

        // 检查管理员权限
        $admin = Db::name('users')->where('id', $userId)->find();
        if (!$admin || ($admin['role'] ?? '') !== 'admin') {
            return json(['code' => 403, 'msg' => '无权限']);
        }

        $page = (int)$request->get('page', 1);
        $pageSize = (int)$request->get('page_size', 20);
        $status = $request->get('status', '2'); // 默认查审核中(2)

        $query = Db::name('users')
            ->field('id, username, phone, real_name, id_card_number, real_name_verified, created_at, updated_at')
            ->where('real_name_verified', $status);

        $total = $query->count();

        $list = $query->order('updated_at', 'desc')
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        return json([
            'code' => 0,
            'data' => [
                'list' => $list,
                'total' => $total,
                'page' => $page,
                'page_size' => $pageSize,
            ]
        ]);
    }

    /**
     * 管理员：审核实名认证
     * POST /api/admin/realname/review
     * 参数: user_id, action (approve/reject), reason(可选)
     */
    public function adminReview(Request $request)
    {
        $adminId = $request->uid ?? null;
        if (!$adminId) {
            return json(['code' => 401, 'msg' => '未登录']);
        }

        // 检查管理员权限
        $admin = Db::name('users')->where('id', $adminId)->find();
        if (!$admin || ($admin['role'] ?? '') !== 'admin') {
            return json(['code' => 403, 'msg' => '无权限']);
        }

        $targetUserId = (int)$request->post('user_id', 0);
        $action = $request->post('action', '');
        $reason = trim($request->post('reason', ''));

        if (!$targetUserId) {
            return json(['code' => 1, 'msg' => '缺少用户ID']);
        }

        if (!in_array($action, ['approve', 'reject'])) {
            return json(['code' => 1, 'msg' => '操作类型无效，应为 approve 或 reject']);
        }

        // 查找目标用户
        $targetUser = Db::name('users')->where('id', $targetUserId)->find();
        if (!$targetUser) {
            return json(['code' => 1, 'msg' => '用户不存在']);
        }

        $newStatus = $action === 'approve' ? 1 : 3;
        $statusText = $action === 'approve' ? '已认证' : '审核拒绝';

        $updateData = [
            'real_name_verified' => $newStatus,
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        // 如果拒绝且提供了原因，记录到备注（可以用 extra 字段或单独字段）
        // 这里简单处理，直接更新状态

        $result = Db::name('users')->where('id', $targetUserId)->update($updateData);

        if ($result !== false) {
            return json([
                'code' => 0,
                'msg' => '审核完成：' . $statusText,
                'data' => [
                    'user_id' => $targetUserId,
                    'real_name_verified' => $newStatus,
                    'status_text' => $statusText,
                ]
            ]);
        }

        return json(['code' => 1, 'msg' => '审核操作失败']);
    }
}
