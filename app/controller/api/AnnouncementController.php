<?php
declare(strict_types=1);

namespace app\controller\api;

use app\model\Announcement;
use think\Request;
use think\Response;

class AnnouncementController
{
    /**
     * 公告列表
     */
    public function list(Request $request): Response
    {
        $page = (int) $request->get('page', 1);
        $pageSize = (int) $request->get('page_size', 10);

        $list = Announcement::visible()
            ->ordered()
            ->field('id,title,sort_order,created_at')
            ->page($page, $pageSize)
            ->select();

        $total = Announcement::visible()->count();

        return json([
            'code' => 0,
            'msg' => 'success',
            'data' => [
                'list' => $list,
                'total' => $total,
            ]
        ]);
    }

    /**
     * 公告详情
     */
    public function detail(Request $request): Response
    {
        $id = (int) $request->route('id');
        $announcement = Announcement::where('id', $id)->where('status', 1)->find();

        if (!$announcement) {
            return json(['code' => 404, 'msg' => '公告不存在', 'data' => null]);
        }

        return json(['code' => 0, 'msg' => 'success', 'data' => $announcement]);
    }
}
