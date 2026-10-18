<?php
declare(strict_types=1);

namespace app\api\controller;

use app\model\Complaint;
use app\model\ComplaintMessage;
use app\model\Transaction;
use app\model\Order;
use think\Request;
use think\Response;
use think\facade\Db;
use think\facade\Log;

class ComplaintController
{
    /**
     * 提交投诉
     */
    public function create(Request $request): Response
    {
        $transactionId = (int) $request->post('transaction_id', 0);
        $description = (string) $request->post('description', '');
        $proofImages = $request->post('proof_images', []);

        if (!$transactionId) {
            return json(['code' => 400, 'msg' => '缺少交易ID', 'data' => null]);
        }

        $securityCheck = $this->validateTextSecurity($description);
        if (!$securityCheck['safe']) {
            return json(['code' => 400, 'msg' => '描述内容包含不安全内容：' . $securityCheck['reason'], 'data' => null]);
        }

        if (mb_strlen($description) < 10) {
            return json(['code' => 400, 'msg' => '请至少输入10个字的问题描述', 'data' => null]);
        }

        if (mb_strlen($description) > 1000) {
            return json(['code' => 400, 'msg' => '描述内容不能超过1000字', 'data' => null]);
        }

        $transaction = Transaction::find($transactionId);
        if (!$transaction) {
            return json(['code' => 404, 'msg' => '交易记录不存在', 'data' => null]);
        }

        $isBuyer = ($transaction->buyer_id === $request->uid);
        $isSeller = ($transaction->seller_id === $request->uid);
        if (!$isBuyer && !$isSeller) {
            return json(['code' => 403, 'msg' => '只有交易双方可以发起投诉', 'data' => null]);
        }

        if (!in_array($transaction->status, [4, 7])) {
            return json(['code' => 400, 'msg' => '当前交易状态不可投诉', 'data' => null]);
        }

        // 同一交易只要有一方已发起申诉（未解决），另一方不得重复发起
        $existing = Complaint::where('transaction_id', $transactionId)->where('status', '<>', 2)->find();
        if ($existing) {
            return json(['code' => 400, 'msg' => '该订单已经被申述，请到个人中心投诉栏查看', 'data' => ['complaint_id' => $existing->id]]);
        }

        $order = Order::find($transaction->order_id);
        $orderNo = '';
        if ($order) {
            $orderNo = $order->order_no ?: ('ORD-' . str_pad((string)$order->id, 6, '0', STR_PAD_LEFT));
        }

        if (is_array($proofImages) && count($proofImages) > 2) {
            return json(['code' => 400, 'msg' => '最多上传2张图片', 'data' => null]);
        }

        if (!is_array($proofImages)) {
            $decoded = json_decode($proofImages, true);
            $proofImages = is_array($decoded) ? $decoded : [];
        }

        Db::startTrans();
        try {
            $complaint = Complaint::create([
                'transaction_id' => $transactionId,
                'order_id' => $transaction->order_id,
                'order_no' => $orderNo,
                'buyer_id' => $transaction->buyer_id,
                'seller_id' => $transaction->seller_id,
                'points_amount' => $transaction->points_amount,
                'unit_price' => $order ? $order->unit_price : 0,
                'total_price' => $transaction->total_price,
                'description' => $description,
                'proof_images' => json_encode($proofImages),
                'status' => 0,
            ]);

            ComplaintMessage::create([
                'complaint_id' => $complaint->id,
                'sender_type' => 0,
                'sender_id' => $request->uid,
                'content' => $description,
            ]);

            Db::commit();
        } catch (\Exception $e) {
            Db::rollback();
            return json(['code' => 500, 'msg' => '提交失败：' . $e->getMessage(), 'data' => null]);
        }

        // 推送通知管理员：新投诉
        try {
            $pushService = new \app\service\PushService();
            $initiatorName = $isBuyer ? '买家' : '卖家';
            $pushService->notifyAllAdmins('新投诉', $initiatorName . ' 发起了交易投诉（订单：' . $orderNo . '）', [
                'type' => 'complaint',
                'data' => [
                    'complaint_id' => $complaint->id,
                    'transaction_id' => $transactionId,
                    'order_no' => $orderNo,
                ]
            ]);
        } catch (\Throwable $e) {
            Log::error('推送通知失败: ' . $e->getMessage());
        }

        return json([
            'code' => 0,
            'msg' => '投诉已提交，我们将尽快处理',
            'data' => ['id' => $complaint->id]
        ]);
    }

    /**
     * 用户追加回复
     */
        public function reply(Request $request): Response
    {
        $id = (int) $request->route('id');
        if (!$id) {
            $id = (int) $request->param('id', 0);
        }

        if (!$id) {
            return json(['code' => 400, 'msg' => '缺少投诉ID', 'data' => null]);
        }

        $content = (string) $request->post('content', '');
        $image = (string) $request->post('image', '');
        if (empty($content) && empty($image)) {
            $rawInput = file_get_contents('php://input');
            if ($rawInput) {
                $jsonData = json_decode($rawInput, true);
                if (is_array($jsonData)) {
                    if (isset($jsonData['content'])) {
                        $content = (string) $jsonData['content'];
                    }
                    if (isset($jsonData['image'])) {
                        $image = (string) $jsonData['image'];
                    }
                }
            }
        }

        $content = trim($content);

        if (mb_strlen($content) < 1 && empty($image)) {
            return json(['code' => 400, 'msg' => '回复内容不能为空', 'data' => null]);
        }

        if (mb_strlen($content) > 1000) {
            return json(['code' => 400, 'msg' => '回复内容不能超过1000字', 'data' => null]);
        }

        if (!empty($content)) {
            $securityCheck = $this->validateTextSecurity($content);
            if (!$securityCheck['safe']) {
                return json(['code' => 400, 'msg' => '回复内容包含不安全内容：' . $securityCheck['reason'], 'data' => null]);
            }
        }

        if (!empty($image)) {
            if (!preg_match('#^/uploads/complaints/#', $image) && !filter_var($image, FILTER_VALIDATE_URL)) {
                return json(['code' => 400, 'msg' => '图片地址不合法', 'data' => null]);
            }
        }

        try {
            $complaint = Complaint::find($id);
            if (!$complaint) {
                return json(['code' => 404, 'msg' => '投诉不存在', 'data' => null]);
            }

            if ($complaint->buyer_id !== $request->uid && $complaint->seller_id !== $request->uid) {
                return json(['code' => 403, 'msg' => '只有交易双方可以回复', 'data' => null]);
            }

            if ($complaint->status == 2) {
                return json(['code' => 400, 'msg' => '该投诉已解决，无法再回复', 'data' => null]);
            }

            ComplaintMessage::create([
                'complaint_id' => $id,
                'sender_type' => 0,
                'sender_id' => $request->uid,
                'content' => $content,
                'image' => $image,
            ]);

            $complaint->status = 0;
            $complaint->save();
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => '回复失败：' . $e->getMessage(), 'data' => null]);
        }

        // 推送通知管理员：投诉有新回复
        try {
            $pushService = new \app\service\PushService();
            $replyRole = ($complaint->buyer_id == $request->uid) ? '买家' : '卖家';
            $pushService->notifyAllAdmins('投诉回复', $replyRole . ' 回复了投诉 #' . $complaint->id, [
                'type' => 'complaint_reply',
                'data' => ['complaint_id' => $complaint->id]
            ]);
        } catch (\Throwable $e) {
            Log::error('推送通知失败: ' . $e->getMessage());
        }

        return json(['code' => 0, 'msg' => '回复成功', 'data' => null]);
    }


     /**
     * 上传投诉凭证图片（含木马检测）
     */
    public function uploadProof(Request $request): Response
    {
        $file = $request->file('file');
        if (!$file) {
            return json(['code' => 400, 'msg' => '请选择图片', 'data' => null]);
        }

        $ext = strtolower($file->extension());
        $allowedExts = ['jpg', 'jpeg', 'png', 'gif'];
        if (!in_array($ext, $allowedExts)) {
            return json(['code' => 400, 'msg' => '只允许上传 JPG/PNG/GIF 图片', 'data' => null]);
        }

        $mime = $file->getMime();
        $allowedMimes = ['image/jpeg', 'image/png', 'image/gif'];
        if (!in_array($mime, $allowedMimes)) {
            return json(['code' => 400, 'msg' => '文件类型不合法', 'data' => null]);
        }

        $fileSize = $file->getSize();
        if ($fileSize > 5 * 1024 * 1024) {
            return json(['code' => 400, 'msg' => '图片大小不能超过5MB', 'data' => null]);
        }

        $tmpPath = $file->getPathname();

        // 验证是否为真实图片
        $imageInfo = @getimagesize($tmpPath);
        if ($imageInfo === false) {
            return json(['code' => 400, 'msg' => '文件不是有效的图片', 'data' => null]);
        }

        // 二次校验：MIME类型必须与getimagesize一致
        $detectedMime = $imageInfo[2];
        $mimeMap = [IMAGETYPE_JPEG => 'image/jpeg', IMAGETYPE_PNG => 'image/png', IMAGETYPE_GIF => 'image/gif'];
        if (!isset($mimeMap[$detectedMime]) || $mimeMap[$detectedMime] !== $mime) {
            return json(['code' => 400, 'msg' => '文件类型不合法，疑似伪造', 'data' => null]);
        }

        // 读取文件原始内容进行木马检测
        $rawContent = file_get_contents($tmpPath, false, null, 0, 1048576);
        if ($rawContent === false) {
            return json(['code' => 400, 'msg' => '文件读取失败', 'data' => null]);
        }

        $trojanCheck = $this->scanImageForTrojan($rawContent);
        if (!$trojanCheck['safe']) {
            return json(['code' => 400, 'msg' => '图片检测不通过：' . $trojanCheck['reason'], 'data' => null]);
        }

        // 图片重新编码（剥离EXIF和嵌入数据）
        $reEncoded = $this->reEncodeImage($tmpPath, $ext);
        if (!$reEncoded) {
            return json(['code' => 400, 'msg' => '图片处理失败，请更换图片', 'data' => null]);
        }

        $uploadDir = public_path() . 'uploads' . DIRECTORY_SEPARATOR . 'complaints';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $fileName = 'complaint_' . date('YmdHis') . '_' . random_int(1000, 9999) . '.' . $ext;
        $savePath = $uploadDir . DIRECTORY_SEPARATOR . $fileName;

        if (!copy($reEncoded, $savePath)) {
            @unlink($reEncoded);
            return json(['code' => 500, 'msg' => '图片保存失败', 'data' => null]);
        }

        @unlink($reEncoded);

        $url = '/uploads/complaints/' . $fileName;

        return json([
            'code' => 0,
            'msg' => '上传成功',
            'data' => ['url' => $url]
        ]);
    }

     /**
     * 扫描图片原始内容中的木马/恶意代码
     */
    private function scanImageForTrojan(string $content): array
    {
        $patterns = [
            // PHP标签（<?php和<?=已足够检测，不检测裸<?避免图片二进制误报）
            '/<\?php/i' => '检测到PHP代码标签',
            '/<\?=/i' => '检测到PHP短标签输出',
            '/<\s*script/i' => '检测到script标签',
            '/<\s*iframe/i' => '检测到iframe标签',
            '/<\s*object/i' => '检测到object标签',
            '/<\s*embed/i' => '检测到embed标签',

            // 一句话木马
            '/eval\s*\(\s*\$_(get|post|request|cookie)/i' => '检测到一句话木马(eval+超全局变量)',
            '/assert\s*\(\s*\$_(get|post|request|cookie)/i' => '检测到一句话木马(assert+超全局变量)',
            '/system\s*\(\s*\$_(get|post|request|cookie)/i' => '检测到系统命令执行(system+超全局变量)',
            '/exec\s*\(\s*\$_(get|post|request|cookie)/i' => '检测到系统命令执行(exec+超全局变量)',
            '/shell_exec\s*\(\s*\$_(get|post|request|cookie)/i' => '检测到系统命令执行(shell_exec+超全局变量)',
            '/passthru\s*\(\s*\$_(get|post|request|cookie)/i' => '检测到系统命令执行(passthru+超全局变量)',
            '/preg_replace\s*\(\s*["\']\/.+\/e/i' => '检测到preg_replace代码执行(e修饰符)',

            // 编码绕过变种
            '/eval\s*\(\s*(base64_decode|gzinflate|gzuncompress|str_rot13|strrev|convert_uudecode)\s*\(/i' => '检测到编码绕过木马(eval+解码函数)',
            '/assert\s*\(\s*(base64_decode|gzinflate|gzuncompress|str_rot13|strrev|convert_uudecode)\s*\(/i' => '检测到编码绕过木马(assert+解码函数)',
            '/call_user_func\s*\(\s*["\']?(eval|assert|system|exec|shell_exec|passthru)/i' => '检测到回调函数木马',
            '/create_function\s*\(/i' => '检测到create_function木马',
            '/preg_replace_callback\s*\(.*["\'](?:eval|assert|system)/i' => '检测到preg_replace_callback木马',

            // 超全局变量直接调用
            '/\$_(get|post|request|cookie|server|files)\s*\[/i' => '检测到超全局变量引用',

            // 文件操作函数（常用于webshell）
            '/file_put_contents\s*\(\s*\$_(get|post|request)/i' => '检测到文件写入木马',
            '/file_get_contents\s*\(\s*\$_(get|post|request)/i' => '检测到文件读取木马',
            '/fwrite\s*\(\s*\$_(get|post|request)/i' => '检测到文件写入木马',
            '/move_uploaded_file\s*\(\s*\$_(get|post|request)/i' => '检测到文件移动木马',
            '/fopen\s*\(\s*\$_(get|post|request)/i' => '检测到文件打开木马',

            // chr拼接绕过
            '/chr\s*\(\s*\d+\s*\)\s*\.\s*chr\s*\(\s*\d+/i' => '检测到chr拼接绕过',

            // 反引号执行
            '/`\s*\$_(get|post|request)/i' => '检测到反引号命令执行',

            // 常见webshell特征
            '/\b(c99|r57|b374k|wso|xshell|webshell|shell\.php|evalshell|phpspy|phpto|m0n0php|phpjackal|spywall|webspell)\b/i' => '检测到已知webshell特征',
            '/\bsystem\s*\(\s*["\']?(id|whoami|uname|ls|cat|wget|curl|bash|sh|nc|python|perl|ruby|chmod|chown|rm|mv|cp)\b/i' => '检测到系统命令调用',

            // .htaccess / .user.ini 注入
            '/\.htaccess/i' => '检测到.htaccess引用',
            '/\.user\.ini/i' => '检测到.user.ini引用',
            '/auto_prepend_file/i' => '检测到auto_prepend_file配置注入',
            '/auto_append_file/i' => '检测到auto_append_file配置注入',

            // GD库绕过（图片中嵌入PHP代码片段）
            '/<\?php\s+@?eval/i' => '检测到图片内嵌PHP执行代码',
            '/<\?php\s+@?assert/i' => '检测到图片内嵌PHP执行代码',
            '/<\?php.*\$_(get|post|request)/is' => '检测到图片内嵌PHP木马',
        ];

        foreach ($patterns as $pattern => $reason) {
            if (preg_match($pattern, $content)) {
                return ['safe' => false, 'reason' => $reason];
            }
        }

        return ['safe' => true, 'reason' => ''];
    }




    /**
     * 我的投诉列表
     */
    public function myComplaints(Request $request): Response
    {
        $page = (int) $request->get('page', 1);
        $pageSize = (int) $request->get('page_size', 20);

        $query = Complaint::where(function($q) use ($request) {
            $q->where('buyer_id', $request->uid)->whereOr('seller_id', $request->uid);
        });
        $list = $query->order('id', 'desc')->page($page, $pageSize)->select();

        $total = Complaint::where(function($q) use ($request) {
            $q->where('buyer_id', $request->uid)->whereOr('seller_id', $request->uid);
        })->count();

        $statusMap = [0 => '待处理', 1 => '已回复', 2 => '已解决'];
        $result = [];
        foreach ($list as $item) {
            $data = $item->toArray();
            $data['proof_images'] = json_decode($item->proof_images ?: '[]', true) ?: [];
            $data['status_text'] = $statusMap[$item->status] ?? '未知';
            $result[] = $data;
        }

        return json([
            'code' => 0,
            'msg' => 'success',
            'data' => ['list' => $result, 'total' => $total]
        ]);
    }

    /**
     * 投诉详情（含对话记录）
     */
    public function detail(Request $request): Response
    {
        $id = (int) $request->route('id');
        if (!$id) {
            $id = (int) $request->param('id', 0);
        }

        $complaint = Complaint::find($id);

        if (!$complaint) {
            return json(['code' => 404, 'msg' => '投诉不存在', 'data' => null]);
        }

        if ($complaint->buyer_id !== $request->uid && $complaint->seller_id !== $request->uid) {
            return json(['code' => 403, 'msg' => '只有交易双方可以查看', 'data' => null]);
        }

        $statusMap = [0 => '待处理', 1 => '已回复', 2 => '已解决'];
        $data = $complaint->toArray();
        $data['proof_images'] = json_decode($complaint->proof_images ?: '[]', true) ?: [];
        $data['status_text'] = $statusMap[$complaint->status] ?? '未知';

        $viewerRole = '';
        if ($complaint->buyer_id === $request->uid) {
            $viewerRole = 'buyer';
        } elseif ($complaint->seller_id === $request->uid) {
            $viewerRole = 'seller';
        }

        $messages = ComplaintMessage::where('complaint_id', $id)
            ->order('id', 'asc')
            ->select();

        $msgList = [];
        foreach ($messages as $msg) {
            $senderText = '管理员';
            $isMine = false;
            if ($msg->sender_type == 0) {
                if ($complaint->buyer_id && $msg->sender_id == $complaint->buyer_id) {
                    $senderText = '买家';
                    $isMine = ($viewerRole === 'buyer');
                } elseif ($complaint->seller_id && $msg->sender_id == $complaint->seller_id) {
                    $senderText = '卖家';
                    $isMine = ($viewerRole === 'seller');
                } else {
                    $senderText = '用户';
                }
            }
            $msgList[] = [
                'id' => $msg->id,
                'sender_type' => $msg->sender_type,
                'sender_id' => $msg->sender_id ?: 0,
                'sender_text' => $senderText,
                'is_mine' => $isMine,
                'content' => $msg->content,
                'image' => $msg->image ?: '',
                'create_time' => $msg->create_time ? $msg->create_time : '',
            ];
        }
        $data['messages'] = $msgList;
        $data['viewer_role'] = $viewerRole;

        return json(['code' => 0, 'msg' => 'success', 'data' => $data]);
    }

    /**
     * 图片重新编码
     */
    private function reEncodeImage(string $tmpPath, string $ext): string|false
    {
        $newTmp = tempnam(sys_get_temp_dir(), 'img_');
        if ($newTmp === false) {
            return false;
        }

        switch ($ext) {
            case 'jpg':
            case 'jpeg':
                $img = @imagecreatefromjpeg($tmpPath);
                if ($img === false) { @unlink($newTmp); return false; }
                $result = imagejpeg($img, $newTmp, 90);
                imagedestroy($img);
                break;
            case 'png':
                $img = @imagecreatefrompng($tmpPath);
                if ($img === false) { @unlink($newTmp); return false; }
                imagealphablending($img, true);
                imagesavealpha($img, true);
                $result = imagepng($img, $newTmp, 6);
                imagedestroy($img);
                break;
            case 'gif':
                $img = @imagecreatefromgif($tmpPath);
                if ($img === false) { @unlink($newTmp); return false; }
                $result = imagegif($img, $newTmp);
                imagedestroy($img);
                break;
            default:
                @unlink($newTmp);
                return false;
        }

        if (!$result) {
            @unlink($newTmp);
            return false;
        }

        return $newTmp;
    }

    
    /**
     * 文本安全校验（增强版 - 含一句话木马/变种检测）
     */
    private function validateTextSecurity(string $text): array
    {
        // SQL注入检测
        $sqlPatterns = [
            '/\bunion\s+select\b/i', '/\bselect\s+.*\bfrom\b/i', '/\binsert\s+into\b/i',
            '/\bdelete\s+from\b/i', '/\bdrop\s+table\b/i', '/\bupdate\s+.*\bset\b/i',
            '/\bsleep\s*\(/i', '/\bbenchmark\s*\(/i', '/\bload_file\s*\(/i',
            '/\binto\s+outfile\b/i', '/\bwaitfor\s+delay\b/i', '/\bxp_cmdshell\b/i',
            '/\b0x[0-9a-f]{8,}\b/i', '/\bconvert\s*\(/i', '/\bcast\s*\(/i',
            '/\bexec\s*\(\s*\(/i', '/\bexecute\s*\(\s*\(/i',
            '/\bdrop\s+database\b/i', '/\btruncate\s+table\b/i', '/\balter\s+table\b/i',
            '/\binformation_schema\b/i', '/\bload_data\s+infile\b/i',
        ];
        foreach ($sqlPatterns as $pattern) {
            if (preg_match($pattern, $text)) {
                return ['safe' => false, 'reason' => '检测到SQL注入特征'];
            }
        }

        // XSS攻击检测
        $xssPatterns = [
            '/<script[^>]*>/i', '/<\/script>/i', '/\bon\w+\s*=\s*["\']?[^"\']*/i',
            '/javascript\s*:/i', '/<iframe[^>]*>/i', '/<object[^>]*>/i',
            '/<embed[^>]*>/i', '/<svg[^>]*on/i', '/vbscript\s*:/i', '/data\s*:\s*text\/html/i',
            '/<img[^>]+onerror/i', '/<img[^>]+onload/i', '/<body[^>]+on/i', '/<a[^>]+javascript/i',
            '/<style[^>]*>/i', '/<link[^>]*>/i', '/<meta[^>]+http-equiv/i',
            '/<form[^>]*>/i', '/<input[^>]*>/i', '/document\.cookie/i', '/document\.location/i',
            '/window\.location/i', '/window\.open/i', '/\.innerHTML/i', '/\.outerHTML/i',
        ];
        foreach ($xssPatterns as $pattern) {
            if (preg_match($pattern, $text)) {
                return ['safe' => false, 'reason' => '检测到XSS攻击特征'];
            }
        }

        // PHP代码注入检测（含一句话木马及变种）
        $phpPatterns = [
            // PHP标签
            '/<\?php/i', '/<\?=/i', '/<\?(?!xml)\s/i', '/<\?(?!xml)$/',
            // 危险函数
            '/\beval\s*\(/i', '/\bassert\s*\(/i',
            '/\bsystem\s*\(/i', '/\bexec\s*\(/i', '/\bshell_exec\s*\(/i', '/\bpassthru\s*\(/i',
            '/\bproc_open\s*\(/i', '/\bpopen\s*\(/i',
            // 编码函数（常用于绕过）
            '/\bbase64_decode\s*\(/i', '/\bbase64_encode\s*\(/i',
            '/\bstr_rot13\s*\(/i', '/\bgzinflate\s*\(/i', '/\bgzuncompress\s*\(/i',
            '/\bgzdecode\s*\(/i', '/\bstrrev\s*\(.*\bstrrev/i', '/\bconvert_uudecode\s*\(/i',
            // 字符串操作（绕过用）
            '/\bstr_replace\s*\(\s*["\']./i', '/\bpreg_replace\s*\(\s*["\']\/.*\/e/i',
            '/\bcreate_function\s*\(/i', '/\bcall_user_func\s*\(/i',
            '/\bcall_user_func_array\s*\(/i',
            // 文件操作
            '/\bfile_put_contents\s*\(/i', '/\bfwrite\s*\(/i', '/\bfopen\s*\(/i',
            '/\bmove_uploaded_file\s*\(/i', '/\bcopy\s*\(\s*\$_/i',
            '/\bfile_get_contents\s*\(\s*\$_/i', '/\breadfile\s*\(/i',
            '/\bfputs\s*\(/i', '/\brename\s*\(\s*\$_/i', '/\bunlink\s*\(\s*\$_/i',
            // 超全局变量
            '/\b$_(get|post|request|cookie|server|files)\b/i', '/\b$globals\s*\[/i',
            // 一句话木马变种
            '/\beval\s*\(\s*\$_/i', '/\bassert\s*\(\s*\$_/i',
            '/\beval\s*\(\s*(base64_decode|gzinflate|gzuncompress|str_rot13|strrev|convert_uudecode)/i',
            '/\bassert\s*\(\s*(base64_decode|gzinflate|gzuncompress|str_rot13|strrev|convert_uudecode)/i',
            '/\bcall_user_func\s*\(\s*["\']?(eval|assert|system|exec|shell_exec|passthru)/i',
            '/\bpreg_replace\s*\(\s*["\']\/.*\/e/i',
            '/\bpreg_replace_callback\s*\(.*["\'](?:eval|assert|system)/i',
            // chr拼接绕过
            '/\bchr\s*\(\s*\d+\s*\)\s*\.\s*chr\s*\(\s*\d+/i',
            // 反引号执行
            '/`\s*\$_(get|post|request)/i',
            // 变量函数
            '/\$\{\$\w+\s*\(/i', '/\$\$\w+\s*\(/i',
            // 命令执行
            '/\bshell_exec\b/i', '/\bpcntl_exec\b/i', '/\bexpect_popen\b/i',
        ];
        foreach ($phpPatterns as $pattern) {
            if (preg_match($pattern, $text)) {
                return ['safe' => false, 'reason' => '检测到可疑代码注入特征'];
            }
        }

        // 木马/后门特征检测
        $trojanPatterns = [
            '/\bwebshell\b/i', '/\bc99\b/i', '/\br57\b/i', '/\bb374k\b/i',
            '/\bwso\b/i', '/\bxshell\b/i', '/\bphpspy\b/i', '/\bphpto\b/i',
            '/\bphpjackal\b/i', '/\bspywall\b/i',
            '/\bshell\b.*\bconnect\b/i', '/\bbackdoor\b/i', '/\breverse\s+shell\b/i',
            '/\bnc\s+-l/i', '/\bbash\s+-i/i', '/\bperl\s+.*\bsocket\b/i',
            '/\bpython\s+.*\bsocket\b/i',
            '/\bmeterpreter\b/i', '/\bpayload\b.*\bshell\b/i',
            '/\bweb_shell\b/i', '/\bcmd_shell\b/i',
            '/\bsystem\s*\(\s*["\']?(id|whoami|uname|ls|cat|wget|curl|bash|sh|nc|python|perl|ruby|chmod|chown|rm\s|mv\s|cp\s)/i',
            '/\bexec\s*\(\s*["\']?(id|whoami|uname|ls|cat|wget|curl|bash|sh|nc|python|perl|ruby|chmod|chown|rm\s|mv\s|cp\s)/i',
            '/\.htaccess/i', '/\.user\.ini/i',
            '/\bauto_prepend_file\b/i', '/\bauto_append_file\b/i',
        ];
        foreach ($trojanPatterns as $pattern) {
            if (preg_match($pattern, $text)) {
                return ['safe' => false, 'reason' => '检测到木马/后门特征'];
            }
        }

        // 敏感凭证信息检测
        $weakPatterns = [
            '/\bpassword\s*[:=]\s*\S+/i', '/\bpasswd\s*[:=]\s*\S+/i', '/\bpwd\s*[:=]\s*\S+/i',
            '/\bsecret\s*[:=]\s*\S+/i', '/\btoken\s*[:=]\s*\S+/i', '/\bapi[_-]?key\s*[:=]\s*\S+/i',
            '/\bprivate[_-]?key\s*[:=]\s*\S+/i', '/\baccess[_-]?key\s*[:=]\s*\S+/i',
        ];
        foreach ($weakPatterns as $pattern) {
            if (preg_match($pattern, $text)) {
                return ['safe' => false, 'reason' => '检测到敏感凭证信息，请勿在描述中包含密码/密钥'];
            }
        }

        // HTML标签过滤
        $stripped = strip_tags($text);
        if ($stripped !== $text) {
            return ['safe' => false, 'reason' => '描述中包含HTML标签，请使用纯文本'];
        }

        return ['safe' => true, 'reason' => ''];
    }

}
