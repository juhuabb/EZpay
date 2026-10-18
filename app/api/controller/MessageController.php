<?php
declare(strict_types=1);
namespace app\api\controller;
use think\Request;
use think\facade\Db;
use app\model\Message;
use app\model\Broadcast;

class MessageController
{
    /**
     * 获取当前用户未读消息数量（含个人站内信 + 未读广播）
     * GET /messages/unread-count
     */
    public function unreadCount(Request $request)
    {
        $uid = $request->uid;

        // 个人站内信未读数
        $personalCount = Message::where('user_id', $uid)
            ->where('is_read', 0)
            ->count();

        // 未读广播数（只统计用户注册后创建的广播）
        $readBroadcastIds = Db::table('broadcast_reads')
            ->where('user_id', $uid)
            ->column('broadcast_id');

        $userCreatedAt = Db::table('users')->where('id', $uid)->value('created_at');

        $broadcastQuery = Broadcast::when($userCreatedAt, function($q) use ($userCreatedAt) {
            $q->where('created_at', '>=', $userCreatedAt);
        });
        if (!empty($readBroadcastIds)) {
            $broadcastQuery->whereNotIn('id', $readBroadcastIds);
        }
        $broadcastCount = $broadcastQuery->count();

        return json([
            'code' => 0,
            'data' => ['count' => $personalCount + $broadcastCount],
        ]);
    }

    /**
     * 获取消息列表（个人站内信 + 未读广播，分页，按时间倒序）
     * GET /messages?page=1&page_size=20
     */
    public function messageList(Request $request)
    {
        $uid      = $request->uid;
        $page     = (int) $request->param('page', 1);
        $pageSize = (int) $request->param('page_size', 20);
        $page     = max(1, $page);
        $pageSize = min(100, max(1, $pageSize));

        // 个人站内信
        $personalMessages = Message::where('user_id', $uid)
            ->order('created_at', 'DESC')
            ->select()
            ->toArray();

        // 未读广播（合并到列表中，已读广播不再显示）
        $readBroadcastIds = Db::table('broadcast_reads')
            ->where('user_id', $uid)
            ->column('broadcast_id');

        $userCreatedAt = Db::table('users')->where('id', $uid)->value('created_at');

        $broadcastQuery = Broadcast::when($userCreatedAt, function($q) use ($userCreatedAt) {
            $q->where('created_at', '>=', $userCreatedAt);
        });
        if (!empty($readBroadcastIds)) {
            $broadcastQuery->whereNotIn('id', $readBroadcastIds);
        }
        $broadcasts = $broadcastQuery->order('created_at', 'DESC')->select()->toArray();

        // 合并并统一格式
        $merged = [];
        foreach ($personalMessages as $msg) {
            $merged[] = [
                'id'          => $msg['id'],
                'type'        => 'personal',
                'title'       => $msg['title'],
                'content'     => $msg['content'],
                'msg_type'    => $msg['type'] ?? 'system',
                'related_id'  => $msg['related_id'] ?? 0,
                'is_read'     => $msg['is_read'],
                'created_at'  => $msg['created_at'],
            ];
        }
        foreach ($broadcasts as $b) {
            $merged[] = [
                'id'          => $b['id'],
                'type'        => 'broadcast',
                'title'       => $b['title'],
                'content'     => $b['content'],
                'msg_type'    => 'broadcast',
                'related_id'  => 0,
                'is_read'     => 0,
                'created_at'  => $b['created_at'],
            ];
        }

        // 按时间倒序
        usort($merged, function($a, $b) {
            return strcmp($b['created_at'] ?? '', $a['created_at'] ?? '');
        });

        // 分页
        $total = count($merged);
        $offset = ($page - 1) * $pageSize;
        $list = array_slice($merged, $offset, $pageSize);

        return json([
            'code' => 0,
            'data' => [
                'list'      => $list,
                'total'     => $total,
                'page'      => $page,
                'page_size' => $pageSize,
            ],
        ]);
    }

    /**
     * 标记消息为已读
     * POST /messages/read
     * body: { message_id: N, type: "personal"|"broadcast" }
     */
    public function markRead(Request $request)
    {
        $uid       = $request->uid;
        $messageId = (int) $request->param('message_id', 0);
        $type      = $request->param('type', 'personal');

        if ($messageId <= 0) {
            return json(['code' => 1, 'msg' => '参数错误']);
        }

        if ($type === 'broadcast') {
            // 标记广播已读
            $exists = Db::table('broadcast_reads')
                ->where('broadcast_id', $messageId)
                ->where('user_id', $uid)
                ->find();
            if (!$exists) {
                Db::table('broadcast_reads')->insert([
                    'broadcast_id' => $messageId,
                    'user_id'      => $uid,
                    'read_at'      => date('Y-m-d H:i:s'),
                ]);
            }
            return json(['code' => 0, 'msg' => 'ok']);
        }

        // 个人站内信标记已读
        $message = Message::where('id', $messageId)
            ->where('user_id', $uid)
            ->find();
        if (!$message) {
            return json(['code' => 1, 'msg' => '消息不存在']);
        }

        if ($message->is_read == 0) {
            $message->is_read = 1;
            $message->save();
        }

        return json(['code' => 0, 'msg' => 'ok', 'data' => ['message' => $message]]);
    }
}
