<?php
declare(strict_types=1);

namespace app\controller\admin;

use app\model\Complaint;
use think\Request;
use think\Response;

class ComplaintManage
{
    /**
     * 投诉列表
     */
    public function list(Request $request): Response
    {
        $page = (int) $request->get('page', 1);
        $pageSize = (int) $request->get('page_size', 20);
        $status = $request->get('status');

        $query = Complaint::with([
            'buyer' => function($q) { $q->field('id,username,nickname'); },
            'seller' => function($q) { $q->field('id,username,nickname'); },
        ]);

        if ($status !== null && $status !== '') {
            $query->where('status', (int) $status);
        }

        $list = $query->order('id', 'desc')
            ->page($page, $pageSize)
            ->select();

        $total = $query->count();

        return json([
            'code' => 0,
            'msg' => 'success',
            'data' => ['list' => $list, 'total' => $total]
        ]);
    }

    /**
     * 投诉详情
     */
    public function detail(Request $request): Response
    {
        $id = (int) $request->route('id');
        $complaint = Complaint::with([
            'buyer' => function($q) { $q->field('id,username,nickname,phone'); },
            'seller' => function($q) { $q->field('id,username,nickname,phone'); },
            'order',
            'transaction',
        ])->find($id);

        if (!$complaint) {
            return json(['code' => 404, 'msg' => '投诉不存在', 'data' => null]);
        }

        return json(['code' => 0, 'msg' => 'success', 'data' => $complaint]);
    }

    /**
     * 回复投诉
     */
    public function reply(Request $request): Response
    {
        $id = (int) $request->route('id');
        $reply = (string) $request->post('reply', '');

        if (mb_strlen($reply) < 5) {
            return json(['code' => 400, 'msg' => '回复内容至少5个字', 'data' => null]);
        }

        if (mb_strlen($reply) > 2000) {
            return json(['code' => 400, 'msg' => '回复内容不能超过2000字', 'data' => null]);
        }

        // 安全校验回复内容
        $stripped = strip_tags($reply);
        if ($stripped !== $reply) {
            return json(['code' => 400, 'msg' => '回复内容不能包含HTML标签', 'data' => null]);
        }

        // 检测SQL注入
        if (preg_match('/\b(union\s+select|sleep\s*\(|benchmark\s*\(|load_file\s*\(|into\s+outfile|xp_cmdshell)\b/i', $reply)) {
            return json(['code' => 400, 'msg' => '回复内容包含不安全内容', 'data' => null]);
        }

        $complaint = Complaint::find($id);
        if (!$complaint) {
            return json(['code' => 404, 'msg' => '投诉不存在', 'data' => null]);
        }

        $complaint->admin_reply = $reply;
        $complaint->admin_id = $request->admin_uid ?? 0;
        $complaint->status = 1; // 已回复
        $complaint->save();

        return json(['code' => 0, 'msg' => '回复成功', 'data' => null]);
    }

    /**
     * 标记为已解决
     */
    public function resolve(Request $request): Response
    {
        $id = (int) $request->route('id');
        $complaint = Complaint::find($id);
        if (!$complaint) {
            return json(['code' => 404, 'msg' => '投诉不存在', 'data' => null]);
        }

        $complaint->status = 2; // 已解决
        $complaint->save();

        return json(['code' => 0, 'msg' => '已标记为已解决', 'data' => null]);
    }
}
