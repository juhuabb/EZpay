<?php
declare(strict_types=1);
namespace app\controller\admin;

use think\facade\Db;
use think\facade\Request;

class ContentManage
{
    // 列表（支持 type 筛选）
    public function list()
    {
        $page = Request::param('page', 1);
        $limit = Request::param('limit', 20);
        $type = Request::param('type', '');
        
        $query = Db::name('announcements');
        if ($type !== '') {
            $query->where('type', $type);
        }
        
        $list = $query->order('sort_order', 'desc')
            ->order('id', 'desc')
            ->page($page, $limit)
            ->select()->toArray();
        $total = Db::name('announcements')->when($type !== '', function($q) use ($type) { return $q->where('type', $type); })->count();
        
        return json(['code' => 0, 'data' => ['list' => $list, 'total' => $total]]);
    }

    // 新增
    public function create()
    {
        $data = Request::post();
        if (empty($data['title'])) return json(['code' => 1, 'msg' => '标题不能为空']);
        if (empty($data['content'])) return json(['code' => 1, 'msg' => '内容不能为空']);
        
        $insertData = [
            'title' => $data['title'],
            'content' => $data['content'],
            'type' => $data['type'] ?? 'announcement',
            'status' => (int)($data['status'] ?? 1),
            'sort_order' => (int)($data['sort_order'] ?? 0),
        ];
        
        $id = Db::name('announcements')->insertGetId($insertData);
        return json(['code' => 0, 'msg' => '发布成功', 'data' => ['id' => $id]]);
    }

    // 编辑
    public function update()
    {
        $id = (int)Request::param('id');
        $data = Request::post();
        
        $updateData = [];
        if (!empty($data['title'])) $updateData['title'] = $data['title'];
        if (!empty($data['content'])) $updateData['content'] = $data['content'];
        if (isset($data['type'])) $updateData['type'] = $data['type'];
        if (isset($data['status'])) $updateData['status'] = (int)$data['status'];
        if (isset($data['sort_order'])) $updateData['sort_order'] = (int)$data['sort_order'];
        
        Db::name('announcements')->where('id', $id)->update($updateData);
        return json(['code' => 0, 'msg' => '更新成功']);
    }

    // 删除
    public function delete()
    {
        $id = (int)Request::param('id');
        Db::name('announcements')->where('id', $id)->delete();
        return json(['code' => 0, 'msg' => '删除成功']);
    }
}
