<?php
declare(strict_types=1);

namespace app\api\controller;

use app\BaseController;
use think\facade\Db;
use think\facade\Log;
use think\Request;
use think\response\Json;

/**
 * 客户留言控制器
 * 用户在前端客服页面留言，后台可查看/回复/关闭
 */
class CustomerMessageController extends BaseController
{
    /**
     * 提交留言（文本+图片一起提交）
     * POST /customer-message/create
     * Body: { content: string, images: [base64, base64] } 最多2张
     */
    public function create(Request $request): Json
    {
        $userId = $request->uid ?? 0;
        if (!$userId) {
            return json(['code' => 401, 'msg' => '请先登录', 'data' => null]);
        }

        $content = trim($request->post('content', ''));
        $images = $request->post('images', []);

        if ($content === '' && empty($images)) {
            return json(['code' => 400, 'msg' => '留言内容和图片不能同时为空']);
        }

        if (mb_strlen($content) > 500) {
            return json(['code' => 400, 'msg' => '留言内容不能超过500字']);
        }

        // 安全检测文本
        if ($content !== '') {
            $textScan = $this->scanText($content);
            if (!$textScan['safe']) {
                Log::warning('客户留言文本安全拦截', [
                    'user_id' => $userId,
                    'reason' => $textScan['reason'],
                    'content_preview' => mb_substr($content, 0, 100)
                ]);
                return json(['code' => 403, 'msg' => $textScan['reason']]);
            }
            $content = $textScan['content'];
        }

        // 处理图片（base64 -> 安全检测 -> 保存）
        $savedImages = [];
        if (is_array($images) && count($images) > 0) {
            if (count($images) > 2) {
                return json(['code' => 400, 'msg' => '最多只能上传2张图片']);
            }
            foreach ($images as $idx => $imgBase64) {
                $result = $this->saveBase64Image($imgBase64, $userId);
                if (!$result['success']) {
                    return json(['code' => 400, 'msg' => '第' . ($idx + 1) . '张图片：' . $result['msg']]);
                }
                $savedImages[] = $result['path'];
            }
        }

        // 获取用户名
        $user = Db::name('users')->where('id', $userId)->field('username')->find();
        $username = $user['username'] ?? ('用户' . $userId);

        try {
            $now = date('Y-m-d H:i:s');
            $messageId = Db::name('customer_messages')->insertGetId([
                'user_id'    => $userId,
                'username'   => $username,
                'content'    => $content,
                'images'     => !empty($savedImages) ? json_encode($savedImages) : null,
                'status'     => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            // 推送通知管理员
            try {
                $pushService = new \app\service\PushService();
                $pushService->notifyAllAdmins('新留言', $username . ' 提交了客服留言', [
                    'type' => 'message',
                    'data' => ['user_id' => $userId, 'username' => $username, 'message_id' => $messageId]
                ]);
            }  catch (\Throwable $e) {
                // 推送失败不影响主流程
                Log::error('推送通知失败: ' . $e->getMessage());
            }

            return json(['code' => 0, 'msg' => '留言提交成功，客服会尽快回复']);
        }  catch (\Throwable $e) {
            Log::error('客户留言保存失败: ' . $e->getMessage());
            return json(['code' => 500, 'msg' => '提交失败，请稍后重试']);
        }
    }

    /**
     * 获取我的留言列表
     * GET /customer-message/list
     */
    public function list(Request $request): Json
    {
        $userId = $request->uid ?? 0;
        if (!$userId) {
            return json(['code' => 401, 'msg' => '请先登录', 'data' => null]);
        }

        $page = (int)($request->get('page', 1));
        $pageSize = 20;

        $total = Db::name('customer_messages')->where('user_id', $userId)->count();
        $list = Db::name('customer_messages')
            ->where('user_id', $userId)
            ->order('created_at', 'desc')
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        // 处理图片URL
        $domain = $request->domain();
        foreach ($list as &$item) {
            if (!empty($item['images'])) {
                $imgs = json_decode($item['images'], true);
                if (is_array($imgs)) {
                    $item['image_urls'] = array_map(function($p) use ($domain) {
                        return $domain . '/storage/' . $p;
                    }, $imgs);
                } else {
                    $item['image_urls'] = [];
                }
            } else {
                $item['image_urls'] = [];
            }
            unset($item['images']);
        }

        return json([
            'code' => 0,
            'data' => [
                'list' => $list,
                'total' => $total,
                'page' => $page,
                'has_more' => ($page * $pageSize) < $total,
            ]
        ]);
    }

    /**
     * 用户关闭自己的留言
     * POST /customer-message/close
     * Body: { id: int }
     */
    public function close(Request $request): Json
    {
        $userId = $request->uid ?? 0;
        if (!$userId) {
            return json(['code' => 401, 'msg' => '请先登录', 'data' => null]);
        }

        $id = (int)$request->post('id', 0);
        if (!$id) {
            return json(['code' => 400, 'msg' => '参数错误']);
        }

        $msg = Db::name('customer_messages')->where('id', $id)->where('user_id', $userId)->find();
        if (!$msg) {
            return json(['code' => 404, 'msg' => '留言不存在']);
        }
        if ($msg['status'] != 1) {
            return json(['code' => 400, 'msg' => '该留言已关闭']);
        }

        Db::name('customer_messages')->where('id', $id)->update([
            'status' => 2,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return json(['code' => 0, 'msg' => '已关闭']);
    }

    // ==================== 安全检测方法 ====================

    /**
     * 文本安全检测
     * 拦截：PHP代码、木马、XSS、SQL注入、危险函数
     */
    private function scanText(string $text): array
    {
        $patterns = [
            '/<\?php/i' => '检测到PHP代码注入',
            '/<\?=/i' => '检测到PHP代码注入',
            '/<\?(?!xml)/i' => '检测到PHP代码注入',
            '/\?>/i' => '检测到PHP代码注入',
            '/\b(eval|assert|system|exec|shell_exec|passthru|proc_open|popen|pcntl_exec)\s*\(/i' => '检测到PHP危险函数调用',
            '/\b(base64_decode|gzinflate|gzuncompress|str_rot13|gzdecode)\s*\(/i' => '检测到PHP危险函数调用',
            '/\b(include|require|include_once|require_once)\s*[\("\']/i' => '检测到PHP文件包含调用',
            '/\b(curl_exec|fsockopen|stream_socket_client)\s*\(/i' => '检测到网络危险函数调用',
            '/<script[^>]*>/i' => '检测到XSS脚本注入',
            '/<\/script>/i' => '检测到XSS脚本注入',
            '/<iframe[^>]*>/i' => '检测到危险HTML标签',
            '/<object[^>]*>/i' => '检测到危险HTML标签',
            '/<embed[^>]*>/i' => '检测到危险HTML标签',
            '/on\w+\s*=\s*["\']/i' => '检测到XSS事件注入',
            '/\bunion\s+select\b/i' => '检测到SQL注入特征',
            '/\binformation_schema\b/i' => '检测到SQL注入特征',
            '/\bload_file\s*\(/i' => '检测到SQL注入特征',
            '/\binto\s+outfile\s*\(/i' => '检测到SQL注入特征',
        ];

        foreach ($patterns as $pattern => $reason) {
            if (preg_match($pattern, $text)) {
                return ['safe' => false, 'content' => '', 'reason' => $reason . '，已拦截'];
            }
        }

        // 过滤HTML标签，只保留纯文本
        $cleaned = strip_tags($text);
        return ['safe' => true, 'content' => $cleaned, 'reason' => ''];
    }

    /**
     * base64图片安全检测+保存
     * 检测：MIME类型、文件头、PHP代码/木马嵌入、真实图片验证
     * 保存：重新编码去除EXIF/metadata
     */
    private function saveBase64Image(string $base64, int $userId): array
    {
        // 去除data URI前缀
        if (strpos($base64, 'data:image') === 0) {
            $parts = explode(',', $base64, 2);
            if (count($parts) !== 2) {
                return ['success' => false, 'msg' => '图片数据格式错误'];
            }
            $base64 = $parts[1];
        }

        // 解码
        $binary = base64_decode($base64, true);
        if ($binary === false || strlen($binary) < 10) {
            return ['success' => false, 'msg' => '图片数据无效'];
        }

        // 大小限制 3MB
        if (strlen($binary) > 3 * 1024 * 1024) {
            return ['success' => false, 'msg' => '图片大小不能超过3MB'];
        }

        // 写入临时文件用于检测
        $tmpFile = tempnam(sys_get_temp_dir(), 'img_scan_');
        file_put_contents($tmpFile, $binary);

        // 验证真实图片
        $imageInfo = @getimagesize($tmpFile);
        if ($imageInfo === false) {
            unlink($tmpFile);
            return ['success' => false, 'msg' => '不是有效的图片文件'];
        }

        $mime = $imageInfo['mime'];
        $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        if (!in_array($mime, $allowedMimes)) {
            unlink($tmpFile);
            return ['success' => false, 'msg' => '仅支持JPG/PNG/GIF/WEBP格式'];
        }

        // 重新编码图片（去除EXIF/metadata/隐藏数据）
        // WebP/PNG/GIF 统一转为 JPEG 输出，确保兼容性
        $ext = 'jpg';
        switch ($mime) {
            case 'image/jpeg':
                $img = @imagecreatefromjpeg($tmpFile);
                break;
            case 'image/png':
                $img = @imagecreatefrompng($tmpFile);
                break;
            case 'image/gif':
                $img = @imagecreatefromgif($tmpFile);
                break;
            case 'image/webp':
                if (function_exists('imagecreatefromwebp')) {
                    $img = @imagecreatefromwebp($tmpFile);
                }
                // GD不支持WebP时，尝试用ImageMagick转换
                if ($img === false || !function_exists('imagecreatefromwebp')) {
                    $jpgTmp = $tmpFile . '.jpg';
                    $cmd = 'convert ' . escapeshellarg($tmpFile) . ' ' . escapeshellarg($jpgTmp) . ' 2>&1';
                    @exec($cmd, $out, $ret);
                    if ($ret === 0 && file_exists($jpgTmp)) {
                        $img = @imagecreatefromjpeg($jpgTmp);
                        @unlink($jpgTmp);
                    }
                    if ($img === false) {
                        @unlink($tmpFile);
                        return ['success' => false, 'msg' => 'WebP图片处理失败，请换成JPG/PNG格式重试'];
                    }
                }
                break;
            default:
                unlink($tmpFile);
                return ['success' => false, 'msg' => '不支持的图片格式'];
        }

        if ($img === false) {
            unlink($tmpFile);
            return ['success' => false, 'msg' => '图片处理失败，请换一张图片重试'];
        }

        // 生成安全文件名
        $safeName = 'cm_' . $userId . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $saveDir = root_path() . 'public/storage/customer_messages/';
        if (!is_dir($saveDir)) {
            mkdir($saveDir, 0755, true);
        }
        $savePath = $saveDir . $safeName;

        // 所有格式统一保存为JPEG
        imagejpeg($img, $savePath, 90);
        imagedestroy($img);
        unlink($tmpFile);

        // 最终验证保存的文件
        $finalCheck = @getimagesize($savePath);
        if ($finalCheck === false) {
            @unlink($savePath);
            return ['success' => false, 'msg' => '图片保存验证失败'];
        }

        return ['success' => true, 'path' => 'customer_messages/' . $safeName];
    }
}
