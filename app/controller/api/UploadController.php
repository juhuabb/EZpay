<?php
declare(strict_types=1);

namespace app\controller\api;

use app\BaseController;
use think\facade\Filesystem;
use think\Request;

/**
 * 文件上传控制器
 */
class UploadController extends BaseController
{
    /**
     * 上传图片
     * POST /api/upload/image
     * 接收 multipart/form-data，字段名 file
     */
    public function image(Request $request)
    {
        $userId = $request->uid ?? ($request->user_id ?? null);
        if (!$userId) {
            return json(['code' => 401, 'msg' => '未登录']);
        }

        $file = $request->file('file');
        if (!$file) {
            return json(['code' => 400, 'msg' => '请选择要上传的文件']);
        }

        // 验证文件类型
        $allowExts = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        $ext = strtolower($file->extension());
        if (!in_array($ext, $allowExts)) {
            return json(['code' => 400, 'msg' => '不支持的文件格式，仅支持：' . implode(',', $allowExts)]);
        }

        // 验证文件大小（最大 5MB）
        $maxSize = 5 * 1024 * 1024;
        if ($file->getSize() > $maxSize) {
            return json(['code' => 400, 'msg' => '文件大小不能超过5MB']);
        }

        // 验证MIME类型
        $allowMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $mime = $file->getMime();
        if (!in_array($mime, $allowMimes)) {
            return json(['code' => 400, 'msg' => '文件类型不合法']);
        }

        try {
            // 保存文件到 public/uploads 目录
            $saveName = \think\facade\Filesystem::disk('public')->putFile('images', $file);
            
            if (!$saveName) {
                return json(['code' => 500, 'msg' => '文件保存失败']);
            }

            // 生成访问URL
            $url = '/storage/' . $saveName;
            
            // 如果是本地存储，返回完整URL
            $baseUrl = $request->domain();
            $fullUrl = $baseUrl . $url;

            return json([
                'code' => 0,
                'msg' => '上传成功',
                'data' => [
                    'url' => $url,
                    'full_url' => $fullUrl,
                    'original_name' => $file->getOriginalName(),
                    'size' => $file->getSize(),
                    'ext' => $ext,
                ]
            ]);

        } catch (\Exception $e) {
            return json([
                'code' => 500,
                'msg' => '上传失败：' . $e->getMessage()
            ]);
        }
    }

    /**
     * 上传头像
     * POST /api/upload/avatar
     */
    public function avatar(Request $request)
    {
        $userId = $request->uid ?? ($request->user_id ?? null);
        if (!$userId) {
            return json(['code' => 401, 'msg' => '未登录']);
        }

        $file = $request->file('file');
        if (!$file) {
            return json(['code' => 400, 'msg' => '请选择要上传的文件']);
        }

        // 验证文件类型
        $allowExts = ['jpg', 'jpeg', 'png'];
        $ext = strtolower($file->extension());
        if (!in_array($ext, $allowExts)) {
            return json(['code' => 400, 'msg' => '头像仅支持：' . implode(',', $allowExts)]);
        }

        // 验证文件大小（最大 2MB）
        $maxSize = 2 * 1024 * 1024;
        if ($file->getSize() > $maxSize) {
            return json(['code' => 400, 'msg' => '头像大小不能超过2MB']);
        }

        try {
            $saveName = \think\facade\Filesystem::disk('public')->putFile('avatars', $file);
            
            if (!$saveName) {
                return json(['code' => 500, 'msg' => '文件保存失败']);
            }

            $url = '/storage/' . $saveName;
            $baseUrl = $request->domain();
            $fullUrl = $baseUrl . $url;

            return json([
                'code' => 0,
                'msg' => '上传成功',
                'data' => [
                    'url' => $url,
                    'full_url' => $fullUrl,
                ]
            ]);

        } catch (\Exception $e) {
            return json([
                'code' => 500,
                'msg' => '上传失败：' . $e->getMessage()
            ]);
        }
    }
}
