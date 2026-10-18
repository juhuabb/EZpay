<?php
declare(strict_types=1);

namespace app\controller\api;

use app\BaseController;
use think\facade\Db;
use think\Request;

/**
 * 后台认证审核管理
 */
class VerifyReviewController extends BaseController
{
    /**
     * 审核页面
     */
    public function index()
    {
        return view('verify_review');
    }

    /**
     * 实名认证审核列表（API）
     */
    public function realNameList(Request $request)
    {
        $status = $request->get('status', '');
        $keyword = $request->get('keyword', '');
        $page = (int)$request->get('page', 1);
        $limit = (int)$request->get('limit', 20);

        $query = Db::name('user_verify')->alias('v')
            ->leftJoin('users u', 'u.id = v.user_id')
            ->field('v.*, u.username, u.email, u.phone');

        if ($status !== '') {
            $query->where('v.status', (int)$status);
        }
        if ($keyword) {
            $query->where(function($q) use ($keyword) {
                $q->whereLike('u.username', "%{$keyword}%")
                  ->whereOr('v.real_name', 'like', "%{$keyword}%")
                  ->whereOr('v.id_card', 'like', "%{$keyword}%");
            });
        }

        $total = $query->count();
        $list = $query->order('v.id', 'desc')
            ->page($page, $limit)
            ->select()
            ->toArray();

        foreach ($list as &$item) {
            $item['status_text'] = match((int)$item['status']) {
                0 => '待审核',
                1 => '已通过',
                2 => '已拒绝',
                default => '未知'
            };
            $item['created_at_fmt'] = $item['created_at'] ? date('Y-m-d H:i', (is_numeric($item['created_at']) ? $item['created_at'] : strtotime($item['created_at']))) : '-';
        }

        return json([
            'code' => 0,
            'data' => [
                'total' => $total,
                'list' => $list,
                'page' => $page,
                'limit' => $limit
            ]
        ]);
    }

    /**
 * 提交实名认证
 */
public function submitRealName(Request $request)
{
    $userId = $request->user_id;
    $data = $request->post();

    $realName = trim($data['real_name'] ?? '');
    $idCard = trim($data['id_card'] ?? '');
    $idCardFront = trim($data['id_card_front'] ?? '');
    $idCardBack = trim($data['id_card_back'] ?? '');

    if (!$realName || !$idCard) {
        return json(['code' => 400, 'msg' => '姓名和身份证号不能为空']);
    }

    // 检查是否有待审核的记录
    $existing = Db::name('user_verify')
        ->where('user_id', $userId)
        ->where('status', 0)
        ->find();

    if ($existing) {
        return json(['code' => 400, 'msg' => '您有待审核的认证，请勿重复提交']);
    }

    $insertData = [
        'user_id'    => $userId,
        'real_name'  => $realName,
        'id_card'    => $idCard,
        'status'     => 0,
        'created_at' => date('Y-m-d H:i:s'),
    ];

    if ($idCardFront) $insertData['id_card_front'] = $idCardFront;
    if ($idCardBack) $insertData['id_card_back'] = $idCardBack;

    Db::name('user_verify')->insert($insertData);

    return json(['code' => 0, 'msg' => '认证信息已提交，请等待审核']);
}

/**
 * 查询实名认证状态
 */
public function verifyStatus(Request $request)
{
    $userId = $request->user_id;

    $verify = Db::name('user_verify')
        ->where('user_id', $userId)
        ->order('id', 'desc')
        ->find();

    if (!$verify) {
        return json(['code' => 0, 'data' => ['status' => -1, 'status_text' => '未认证']]);
    }

    $statusText = match((int)$verify['status']) {
        0 => '待审核',
        1 => '已通过',
        2 => '已拒绝',
        default => '未知'
    };

    $verify['status_text'] = $statusText;
    if (!empty($verify['created_at']) && is_numeric($verify['created_at'])) {
        $verify['created_at_fmt'] = date('Y-m-d H:i:s', $verify['created_at']);
    }

    return json(['code' => 0, 'data' => $verify]);
}



    /**
     * 审核操作
     */
    public function review(Request $request)
    {
        $data = $request->post();
        $verifyId = $data['verify_id'] ?? 0;
        $action = $data['action'] ?? '';

        if (!$verifyId || !in_array($action, ['approve', 'reject'])) {
            return json(['code' => 400, 'msg' => '参数错误']);
        }

        $verify = Db::name('user_verify')->where('id', $verifyId)->find();
        if (!$verify) {
            return json(['code' => 404, 'msg' => '认证记录不存在']);
        }
        if ((int)$verify['status'] !== 0) {
            return json(['code' => 400, 'msg' => '该认证已处理']);
        }

        $newStatus = $action === 'approve' ? 1 : 2;

        // 更新审核记录
        $updateData = [
            'status' => $newStatus,
            'reviewed_at' => date('Y-m-d H:i:s'),
        ];
        if ($action === 'reject' && !empty($data['reason'])) {
            $updateData['review_reason'] = $data['reason'];
        }

        Db::name('user_verify')->where('id', $verifyId)->update($updateData);

        // 更新用户状态
        if ($action === 'approve') {
            Db::name('users')->where('id', $verify['user_id'])->update([
                'real_name_verified' => 1,
                'status' => 1,
            ]);
        } else {
            Db::name('users')->where('id', $verify['user_id'])->update([
                'status' => 1,
            ]);
        }

        return json(['code' => 0, 'msg' => $action === 'approve' ? '审核通过' : '已拒绝']);
    }
}
