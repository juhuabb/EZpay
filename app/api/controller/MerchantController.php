<?php
declare(strict_types=1);

namespace app\api\controller;

use app\model\Merchant;
use app\model\Order;
use app\model\Transaction;
use app\model\User;
use app\service\RsaService;
use think\Request;
use think\Response;

class MerchantController
{
    /**
     * 商户仪表盘数据
     */
    public function merchantDashboard(Request $request): Response
    {
        $user = User::find($request->uid);
        $merchant = Merchant::where('user_id', $user->id)->find();

        if (!$merchant) {
            return json(['code' => 400, 'msg' => '非商户用户', 'data' => null]);
        }

        $totalOrders = Order::where('merchant_id', $merchant->id)->count();
        $activeOrders = Order::where('merchant_id', $merchant->id)->where('status', 1)->count();
        $totalTransactions = Transaction::where('merchant_id', $merchant->id)->where('status', 2)->count();
        $totalVolume = Transaction::where('merchant_id', $merchant->id)
            ->where('status', 2)
            ->sum('total_price');
        $totalPoints = Transaction::where('merchant_id', $merchant->id)
            ->where('status', 2)
            ->sum('points_amount');

        // 今日数据
        $today = date('Y-m-d');
        $todayTransactions = Transaction::where('merchant_id', $merchant->id)
            ->where('status', 2)
            ->whereDay('created_at', $today)
            ->count();
        $todayVolume = Transaction::where('merchant_id', $merchant->id)
            ->where('status', 2)
            ->whereDay('created_at', $today)
            ->sum('total_price');

        return json([
            'code' => 0,
            'msg' => 'success',
            'data' => [
                'total_orders' => $totalOrders,
                'active_orders' => $activeOrders,
                'total_transactions' => $totalTransactions,
                'total_volume' => (float) $totalVolume,
                'total_points' => (float) $totalPoints,
                'today_transactions' => $todayTransactions,
                'today_volume' => (float) $todayVolume,
                // 商户概览信息（与前端Dashboard.vue字段名一致）
                'merchant_name' => $merchant->company_name ?? '',
                'website' => $merchant->website_url ?? '',
                'pay_enabled' => (int)$merchant->payment_enabled === 1,
            ]
        ]);
    }

    public function info(Request $request): Response
    {
        try {
            $userId = $request->user_id;
            $merchant = \app\model\Merchant::where('user_id', $userId)->find();

            if (!$merchant) {
                return json(['code' => 0, 'msg' => 'success', 'data' => [
                    'is_merchant' => false,
                    'merchant' => null
                ]]);
            }

            return json(['code' => 0, 'msg' => 'success', 'data' => [
                'is_merchant' => true,
                'merchant' => [
                    'id' => $merchant->id,
                    'website_url' => $merchant->website_url ?? '',
                    'status' => $merchant->status ?? 0,
                    'payment_enabled' => $merchant->payment_enabled ?? 0,
                    'created_at' => $merchant->created_at ?? '',
                ]
            ]]);
        } catch (\Throwable $e) {
            return json(['code' => 500, 'msg' => '获取商户信息失败：' . $e->getMessage(), 'data' => null]);
        }
    }

    /**
     * 获取API凭证
     */
    public function apiCredentials(Request $request): Response
    {
        $user = User::find($request->uid);
        $merchant = Merchant::where('user_id', $user->id)->find();

        if (!$merchant) {
            return json(['code' => 400, 'msg' => '非商户用户', 'data' => null]);
        }

        return json([
            'code' => 0,
            'msg' => 'success',
            'data' => [
                'api_key' => $merchant->api_key,
                'api_secret' => '******（仅生成时可见）',
                'rsa_public_key' => $merchant->rsa_public_key,
                'payment_enabled' => $merchant->payment_enabled,
                'payment_limit' => $merchant->payment_limit,
                'has_totp' => !empty($merchant->totp_secret),
                'has_rsa' => !empty($merchant->rsa_public_key),
            ]
        ]);
    }

    /**
     * 重置API密钥
     */
    public function resetApiKeys(Request $request): Response
    {
        $user = User::find($request->uid);
        $merchant = Merchant::where('user_id', $user->id)->find();

        if (!$merchant) {
            return json(['code' => 400, 'msg' => '非商户用户', 'data' => null]);
        }

        $merchant->generateApiCredentials();
        $merchant->save();

        return json([
            'code' => 0,
            'msg' => 'API密钥已重置',
            'data' => [
                'api_key' => $merchant->api_key,
                'api_secret' => $merchant->api_secret,
            ]
        ]);
    }

    /**
     * 重置RSA密钥对
     */
    public function resetRsaKeys(Request $request): Response
    {
        $user = User::find($request->uid);
        $merchant = Merchant::where('user_id', $user->id)->find();

        if (!$merchant) {
            return json(['code' => 400, 'msg' => '非商户用户', 'data' => null]);
        }

        $rsaService = new RsaService();
        $keyPair = $rsaService->generateKeyPair();

        $merchant->rsa_private_key = $keyPair['private_key'];
        $merchant->rsa_public_key = $keyPair['public_key'];
        $merchant->save();

        return json([
            'code' => 0,
            'msg' => 'RSA密钥对已重置',
            'data' => [
                'rsa_private_key' => $keyPair['private_key'],
                'rsa_public_key' => $keyPair['public_key'],
            ]
        ]);
    }

    /**
     * 商户交易列表
     */
    public function merchantTransactions(Request $request): Response
    {
        $user = User::find($request->uid);

        $page = (int) $request->get('page', 1);
        $pageSize = (int) $request->get('page_size', 20);
        $type = $request->get('type', 'all');

        // 按类型筛选
        if ($type === 'buy') {
            $query = Transaction::where('buyer_id', $user->id);
        } elseif ($type === 'sell') {
            $query = Transaction::where('seller_id', $user->id);
        } else {
            $query = Transaction::where(function($q) use ($user) {
                $q->where('buyer_id', $user->id)
                  ->whereOr('seller_id', $user->id);
            });
        }

        $list = $query
            ->with(['buyer' => function($query) {
                $query->field('id,username');
            }, 'order' => function($query) {
                $query->field('id,title');
            }])
            ->order('id', 'desc')
            ->page($page, $pageSize)
            ->select();

        $total = $query->count();

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
     * 获取收款方式列表
     * 所有用户都可以设置收款方式，merchant_id 字段实际存储的是用户ID
     */
    public function paymentMethods(Request $request): Response
    {
        $user = User::find($request->uid);
        $userId = $user->id;

        $list = \think\facade\Db::name('payment_methods')
            ->where('merchant_id', $userId)
            ->order('is_default', 'desc')
            ->order('id', 'desc')
            ->select()
            ->toArray();

        return json(['code' => 0, 'msg' => 'success', 'data' => $list]);
    }

    /**
     * 添加收款方式
     * merchant_id 字段直接存储用户ID（users.id），不需要 merchant 记录
     */
    public function addPaymentMethod(Request $request): Response
    {
        $user = User::find($request->uid);
        $userId = $user->id;

        // 验证实名认证
        if (!$user->real_name_verified) {
            return json(['code' => 400, 'msg' => '请先完成实名认证', 'data' => null]);
        }

        $type = $request->param('type', '');
        $accountName = $request->param('account_name', '');

        // 类型验证（5种）
        if (!in_array($type, ['alipay', 'wechat', 'bank', 'qq', 'dcep'])) {
            return json(['code' => 400, 'msg' => '无效的收款方式类型', 'data' => null]);
        }

        // 所有类型都要验证姓名
        if (empty($accountName)) {
            return json(['code' => 400, 'msg' => '姓名不能为空', 'data' => null]);
        }

        // ========== 微信类型验证 ==========
        if ($type === 'wechat') {
            $nameErr = $this->validateWechatName($accountName);
            if ($nameErr) {
                return json(['code' => 400, 'msg' => $nameErr, 'data' => null]);
            }
        }

        // ========== 支付宝类型验证 ==========
        if ($type === 'alipay') {
            // 姓名：纯中文最多4字
            if (!preg_match('/^[\x{4e00}-\x{9fa5}]{1,4}$/u', $accountName)) {
                return json(['code' => 400, 'msg' => '支付宝真实姓名只能是1-4个中文字符', 'data' => null]);
            }
            // 账号不能为空
            $accountNo = $request->param('account_no', '');
            if (empty($accountNo)) {
                return json(['code' => 400, 'msg' => '账号不能为空', 'data' => null]);
            }
            // 账号：6-20位，只允许字母数字@和英文句号，@最多一个
            if (strlen($accountNo) < 6 || strlen($accountNo) > 20) {
                return json(['code' => 400, 'msg' => '支付宝账号长度为6-20位', 'data' => null]);
            }
            if (!preg_match('/^[a-zA-Z0-9._@-]+$/', $accountNo)) {
                return json(['code' => 400, 'msg' => '支付宝账号只能包含字母、数字、-、_、.和@', 'data' => null]);
            }
            if (strpos($accountNo, '..') !== false) {
                return json(['code' => 400, 'msg' => '支付宝账号不能有两个连续的英文句号', 'data' => null]);
            }
            if (substr_count($accountNo, '@') > 1) {
                return json(['code' => 400, 'msg' => '支付宝账号最多一个@符号', 'data' => null]);
            }
        }

        // ========== 银行卡类型验证 ==========
        if ($type === 'bank') {
            $accountNo = $request->param('account_no', '');
            $confirmNo = $request->param('confirm_account_no', '');
            if (empty($accountNo)) {
                return json(['code' => 400, 'msg' => '账号不能为空', 'data' => null]);
            }
            if (!preg_match('/^\d{16,19}$/', $accountNo)) {
                return json(['code' => 400, 'msg' => '银行卡号只能为16-19位纯数字', 'data' => null]);
            }
            if ($accountNo !== $confirmNo) {
                return json(['code' => 400, 'msg' => '两次输入的银行卡号不一致', 'data' => null]);
            }
            if (!preg_match('/^[\x{4e00}-\x{9fa5}]{1,4}$/u', $accountName)) {
                return json(['code' => 400, 'msg' => '账户姓名只能最多4个中文字符', 'data' => null]);
            }
            $bankName = $request->param('bank_name', '');
            $bankBranch = $request->param('bank_branch', '');
            if (!preg_match('/^[\x{4e00}-\x{9fa5}0-9]{1,30}$/u', $bankName)) {
                return json(['code' => 400, 'msg' => '开户银行只能是中文和数字，不超过30字', 'data' => null]);
            }
            if (!preg_match('/^[\x{4e00}-\x{9fa5}0-9]{1,30}$/u', $bankBranch)) {
                return json(['code' => 400, 'msg' => '开户行名称只能是中文和数字，不超过30字', 'data' => null]);
            }
        }

        // ========== QQ收款类型验证 ==========
        if ($type === 'qq') {
            // 姓名验证
            if (!preg_match('/^[\x{4e00}-\x{9fa5}]{1,4}$/u', $accountName)) {
                return json(['code' => 400, 'msg' => '姓名只能为1-4个中文字符', 'data' => null]);
            }
            // QQ号：5-11位纯数字
            $qqNo = $request->param('account_no', '');
            if (empty($qqNo)) {
                return json(['code' => 400, 'msg' => 'QQ号不能为空', 'data' => null]);
            }
            if (!preg_match('/^\d{5,11}$/', $qqNo)) {
                return json(['code' => 400, 'msg' => 'QQ号只能为5-11位纯数字', 'data' => null]);
            }
            // QQ收款码：必须上传
            $qrcodeUrl = $request->param('qrcode_url', '');
            $file = $request->file('qrcode_file');
            if (empty($qrcodeUrl) && !$file) {
                return json(['code' => 400, 'msg' => '请上传QQ收款码', 'data' => null]);
            }
        }

        // ========== 数字人民币类型验证 ==========
        if ($type === 'dcep') {
            // 姓名验证
            if (!preg_match('/^[\x{4e00}-\x{9fa5}]{1,4}$/u', $accountName)) {
                return json(['code' => 400, 'msg' => '姓名只能为1-4个中文字符', 'data' => null]);
            }
            // 钱包编号：纯数字
            $walletNo = $request->param('account_no', '');
            if (empty($walletNo)) {
                return json(['code' => 400, 'msg' => '钱包编号不能为空', 'data' => null]);
            }
            if (!preg_match('/^\d+$/', $walletNo)) {
                return json(['code' => 400, 'msg' => '钱包编号必须为纯数字', 'data' => null]);
            }
            // 备注名称：可选，如果
        // 备注名称：可选，如果填了要验证
            $remarkName = $request->param('remark_name', '');
            if (!empty($remarkName) && !preg_match('/^[\x{4e00}-\x{9fa5}a-zA-Z0-9_\-]{1,20}$/u', $remarkName)) {
                return json(['code' => 400, 'msg' => '备注名称只能包含中文、英文、数字，不超过20字', 'data' => null]);
            }
            // 编码图：必须上传
            $codeImageUrl = $request->param('code_image_url', '');
            $codeImageFile = $request->file('code_image_file');
            if (empty($codeImageUrl) && !$codeImageFile) {
                return json(['code' => 400, 'msg' => '请上传编码图', 'data' => null]);
            }
            // 实名图：必须上传
            $idCardImageUrl = $request->param('id_card_image_url', '');
            $idCardImageFile = $request->file('id_card_image_file');
            if (empty($idCardImageUrl) && !$idCardImageFile) {
                return json(['code' => 400, 'msg' => '请上传实名图', 'data' => null]);
            }
        }

        // 检查数量上限
        $count = \think\facade\Db::name('payment_methods')->where('merchant_id', $userId)->count();
        if ($count >= 5) {
            return json(['code' => 400, 'msg' => '最多添加5个收款方式', 'data' => null]);
        }

        $isDefault = $count == 0 ? 1 : 0;

        $data = [
            'merchant_id' => $userId,
            'type' => $type,
            'account_name' => $accountName,
            'account_no' => $request->param('account_no', ''),
            'qrcode_url' => $request->param('qrcode_url', ''),
            'code_image_url' => $request->param('code_image_url', ''),
            'id_card_image' => $request->param('id_card_image_url', ''),
            'bank_name' => $request->param('bank_name', ''),
            'bank_branch' => $request->param('bank_branch', ''),
            'remark_name' => $request->param('remark_name', ''),
            'is_default' => $isDefault,
            'created_at' => date('Y-m-d H:i:s'),
        ];

        // 处理上传的收款码图片（支付宝/微信/QQ的 qrcode_file）
        $file = $request->file('qrcode_file');
        if ($file) {
            $uploadResult = $this->handleImageUpload($file);
            if ($uploadResult['error']) {
                return json(['code' => 400, 'msg' => $uploadResult['error'], 'data' => null]);
            }
            $data['qrcode_url'] = $uploadResult['url'];
        }

        // 数字人民币：编码图
        $codeImageFile = $request->file('code_image_file');
        if ($codeImageFile) {
            $uploadResult = $this->handleImageUpload($codeImageFile);
            if ($uploadResult['error']) {
                return json(['code' => 400, 'msg' => $uploadResult['error'], 'data' => null]);
            }
            $data['code_image_url'] = $uploadResult['url'];
        }

        // 数字人民币：实名图（存入 id_card_image 字段）
        $idCardImageFile = $request->file('id_card_image_file');
        if ($idCardImageFile) {
            $uploadResult = $this->handleImageUpload($idCardImageFile);
            if ($uploadResult['error']) {
                return json(['code' => 400, 'msg' => $uploadResult['error'], 'data' => null]);
            }
            $data['id_card_image'] = $uploadResult['url'];
        }

        // 数字人民币：前端已上传的编码图URL
        $codeImageUrl = $request->param('code_image_url', '');
        if (!empty($codeImageUrl) && !isset($data['code_image_url'])) {
            $data['code_image_url'] = $codeImageUrl;
        }

        // 数字人民币：前端已上传的实名图URL
        $idCardImageUrl = $request->param('id_card_image_url', '');
        if (!empty($idCardImageUrl) && !isset($data['id_card_image'])) {
            $data['id_card_image'] = $idCardImageUrl;
        }

        try {
            $id = \think\facade\Db::name('payment_methods')->insertGetId($data);
            return json(['code' => 0, 'msg' => '添加成功', 'data' => ['id' => $id]]);
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => '数据库错误：' . $e->getMessage() . ' | 字段：' . json_encode(array_keys($data)), 'data' => null]);
        }
    }

    /**
     * 更新收款方式
     */
    public function updatePaymentMethod(Request $request): Response
    {
        $user = User::find($request->uid);
        $userId = $user->id;

        $id = (int)$request->param('id');
        $record = \think\facade\Db::name('payment_methods')
            ->where('id', $id)
            ->where('merchant_id', $userId)
            ->find();
        if (!$record) {
            return json(['code' => 404, 'msg' => '收款方式不存在', 'data' => null]);
        }

        $update = [];
        $type = $request->param('type', '');
        if (!empty($type)) {
            if (!in_array($type, ['alipay', 'wechat', 'bank', 'qq', 'dcep'])) {
                return json(['code' => 400, 'msg' => '无效的收款方式类型', 'data' => null]);
            }
            $update['type'] = $type;
        }

        $accountName = $request->param('account_name');
        if (isset($accountName) && $accountName !== '') {
            $update['account_name'] = $accountName;
        }

        $accountNo = $request->param('account_no');
        if (isset($accountNo)) {
            $update['account_no'] = $accountNo;
        }

        if ($request->param('bank_name') !== null) {
            $update['bank_name'] = $request->param('bank_name', '');
        }
        if ($request->param('bank_branch') !== null) {
            $update['bank_branch'] = $request->param('bank_branch', '');
        }

        // 备注名称（数字人民币）
        $remarkName = $request->param('remark_name');
        if ($remarkName !== null) {
            $update['remark_name'] = $remarkName;
        }

        // 前端已上传的收款码URL
        $qrcodeUrl = $request->param('qrcode_url', '');
        if ($qrcodeUrl !== '') {
            $update['qrcode_url'] = $qrcodeUrl;
        }

        // 前端已上传的编码图URL
        $codeImageUrl = $request->param('code_image_url', '');
        if ($codeImageUrl !== '') {
            $update['code_image_url'] = $codeImageUrl;
        }

        // 前端已上传的实名图URL
        $idCardImageUrl = $request->param('id_card_image_url', '');
        if ($idCardImageUrl !== '') {
            $update['id_card_image'] = $idCardImageUrl;
        }

        // 支付宝修改时验证
        $finalType = $update['type'] ?? $record['type'];
        if ($finalType === 'alipay') {
            if (isset($update['account_name']) && !preg_match('/^[\x{4e00}-\x{9fa5}]{1,4}$/u', $update['account_name'])) {
                return json(['code' => 400, 'msg' => '支付宝真实姓名只能是1-4个中文字符', 'data' => null]);
            }
            if (isset($update['account_no'])) {
                $ano = $update['account_no'];
                if (strlen($ano) < 6 || strlen($ano) > 20) {
                    return json(['code' => 400, 'msg' => '支付宝账号长度为6-20位', 'data' => null]);
                }
                if (!preg_match('/^[a-zA-Z0-9._@-]+$/', $ano)) {
                    return json(['code' => 400, 'msg' => '支付宝账号只能包含字母、数字、-、_、.和@', 'data' => null]);
                }
                if (substr_count($ano, '@') > 1) {
                    return json(['code' => 400, 'msg' => '支付宝账号最多一个@符号', 'data' => null]);
                }
            }
        }

        // QQ修改时验证
        if ($finalType === 'qq') {
            if (isset($update['account_no'])) {
                if (!preg_match('/^\d{5,11}$/', $update['account_no'])) {
                    return json(['code' => 400, 'msg' => 'QQ号只能为5-11位纯数字', 'data' => null]);
                }
            }
            if (isset($update['account_name']) && !preg_match('/^[\x{4e00}-\x{9fa5}]{1,4}$/u', $update['account_name'])) {
                return json(['code' => 400, 'msg' => '姓名只能为1-4个中文字符', 'data' => null]);
            }
        }

        // 数字人民币修改时验证
        if ($finalType === 'dcep') {
            if (isset($update['account_no'])) {
                if (!preg_match('/^\d+$/', $update['account_no'])) {
                    return json(['code' => 400, 'msg' => '钱包编号必须为纯数字', 'data' => null]);
                }
            }
            if (isset($update['account_name']) && !preg_match('/^[\x{4e00}-\x{9fa5}]{1,4}$/u', $update['account_name'])) {
                return json(['code' => 400, 'msg' => '姓名只能为1-4个中文字符', 'data' => null]);
            }
            if (isset($update['remark_name']) && !empty($update['remark_name']) && !preg_match('/^[\x{4e00}-\x{9fa5}a-zA-Z0-9_\-]{1,20}$/u', $update['remark_name'])) {
                return json(['code' => 400, 'msg' => '备注名称只能包含中文、英文、数字，不超过20字', 'data' => null]);
            }
        }

        // 设置默认收款方式
        if ($request->param('is_default') !== null) {
            $update['is_default'] = $request->param('is_default') ? 1 : 0;
            if ($update['is_default']) {
                \think\facade\Db::name('payment_methods')
                    ->where('merchant_id', $userId)
                    ->where('id', '<>', $id)
                    ->update(['is_default' => 0]);
            }
        }

        // 处理上传的收款码图片（同add方法的安全验证）
        $file = $request->file('qrcode_file');
        if ($file) {
            $uploadResult = $this->handleImageUpload($file);
            if ($uploadResult['error']) {
                return json(['code' => 400, 'msg' => $uploadResult['error'], 'data' => null]);
            }
            $update['qrcode_url'] = $uploadResult['url'];
        }

        // 数字人民币：编码图
        $codeImageFile = $request->file('code_image_file');
        if ($codeImageFile) {
            $uploadResult = $this->handleImageUpload($codeImageFile);
            if ($uploadResult['error']) {
                return json(['code' => 400, 'msg' => $uploadResult['error'], 'data' => null]);
            }
            $update['code_image_url'] = $uploadResult['url'];
        }

        // 数字人民币：实名图
        $idCardImageFile = $request->file('id_card_image_file');
        if ($idCardImageFile) {
            $uploadResult = $this->handleImageUpload($idCardImageFile);
            if ($uploadResult['error']) {
                return json(['code' => 400, 'msg' => $uploadResult['error'], 'data' => null]);
            }
            $update['id_card_image'] = $uploadResult['url'];
        }

        if (!empty($update)) {
            \think\facade\Db::name('payment_methods')->where('id', $id)->update($update);
        }
        return json(['code' => 0, 'msg' => '更新成功', 'data' => null]);
    }

    /**
     * 删除收款方式
     */
    public function deletePaymentMethod(Request $request): Response
    {
        $user = User::find($request->uid);
        $userId = $user->id;

        $id = (int)$request->param('id');

        $record = \think\facade\Db::name('payment_methods')
            ->where('id', $id)
            ->where('merchant_id', $userId)
            ->find();

        if (!$record) {
            return json(['code' => 404, 'msg' => '收款方式不存在', 'data' => null]);
        }

        \think\facade\Db::name('payment_methods')->where('id', $id)->delete();

        return json(['code' => 0, 'msg' => '删除成功', 'data' => null]);
    }

    /**
     * 通用图片上传处理（含安全验证）
     * 返回 ['url' => '...', 'error' => null] 或 ['url' => null, 'error' => '...']
     */
    private function handleImageUpload($file): array
    {
        $allowExt = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp'];
        $ext = strtolower($file->extension());
        if (!in_array($ext, $allowExt)) {
            return ['url' => null, 'error' => '只允许上传图片(jpg/png/gif/bmp/webp)'];
        }
        $allowMime = ['image/jpeg', 'image/png', 'image/gif', 'image/bmp', 'image/webp'];
        if (!in_array($file->mimetype(), $allowMime)) {
            return ['url' => null, 'error' => '文件格式不合法，不是有效图片'];
        }
        $check = @getimagesize($file->getPathname());
        if ($check === false || strpos($check['mime'], 'image/') !== 0) {
            return ['url' => null, 'error' => '文件不是有效图片，可能包含恶意内容'];
        }
        if ($file->getSize() > 5 * 1024 * 1024) {
            return ['url' => null, 'error' => '图片大小不能超过5MB'];
        }
        $saveName = md5(microtime(true)) . '_' . random_int(1000, 9999) . '.' . $ext;
        $uploadDir = public_path() . 'uploads/payment/' . date('Ymd');
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        $file->move($uploadDir, $saveName);
        return ['url' => '/uploads/payment/' . date('Ymd') . '/' . $saveName, 'error' => null];
    }

    ////////////////////////////
    private function validateWechatName($name) {
        if (empty($name)) {
            return '微信姓名不能为空';
        }
        if (mb_strlen($name) > 4) {
            return '微信姓名最多4个字符';
        }
        // 只允许中文字符
        if (!preg_match('/^[\x{4e00}-\x{9fa5}]+$/u', $name)) {
            return '微信姓名只能包含中文，不能有英文、数字或符号';
        }
        return '';
    }

    /**
     * 安全上传收款码图片（简化版，不依赖GD库）
     * POST /merchant/upload-qrcode
     */
    public function uploadQrcode(Request $request): Response
    {
        try {
            $user = User::find($request->uid);
            if (!$user) {
                return json(['code' => 401, 'msg' => '未登录', 'data' => null]);
            }

            $file = $request->file('file');
            if (!$file) {
                return json(['code' => 400, 'msg' => '请选择图片', 'data' => null]);
            }

            $allowExt = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            $ext = strtolower($file->extension());
            if (!in_array($ext, $allowExt)) {
                return json(['code' => 400, 'msg' => '只允许上传图片(jpg/png/gif/webp)', 'data' => null]);
            }

            $maxSize = 5 * 1024 * 1024;
            if ($file->getSize() > $maxSize) {
                return json(['code' => 400, 'msg' => '图片大小不能超过5MB', 'data' => null]);
            }

            $allowMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            $mime = $file->getMime();
            if (!in_array($mime, $allowMimes)) {
                return json(['code' => 400, 'msg' => '文件类型不合法', 'data' => null]);
            }

            $tmpPath = $file->getPathname();
            
            // 基本图片验证（不依赖GD库）
            $imageInfo = @getimagesize($tmpPath);
            if ($imageInfo === false) {
                return json(['code' => 400, 'msg' => '文件不是有效图片', 'data' => null]);
            }

            // 木马检测
            $rawContent = file_get_contents($tmpPath, false, null, 0, 1048576);
            if ($rawContent === false) {
                return json(['code' => 400, 'msg' => '文件读取失败', 'data' => null]);
            }
            $trojanCheck = $this->scanImageForTrojan($rawContent);
            if (!$trojanCheck['safe']) {
                return json(['code' => 400, 'msg' => '图片检测不通过：' . $trojanCheck['reason'], 'data' => null]);
            }

            // 创建上传目录
            $uploadDir = public_path() . 'uploads/payment/' . date('Ymd');
            if (!is_dir($uploadDir)) {
                if (!mkdir($uploadDir, 0755, true)) {
                    return json(['code' => 500, 'msg' => '创建上传目录失败', 'data' => null]);
                }
            }

            // 直接移动文件（不重编码）
            $fileName = 'qrcode_' . date('YmdHis') . '_' . random_int(1000, 9999) . '.' . $ext;
            $savePath = $uploadDir . DIRECTORY_SEPARATOR . $fileName;

            if (!$file->move($uploadDir, $fileName)) {
                return json(['code' => 500, 'msg' => '图片保存失败', 'data' => null]);
            }

            $url = '/uploads/payment/' . date('Ymd') . '/' . $fileName;
            return json(['code' => 0, 'msg' => '上传成功', 'data' => ['url' => $url]]);
            
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => '上传异常：' . $e->getMessage(), 'data' => null]);
        }
    }

    /**
     * 扫描图片原始内容中的木马/恶意代码
     */
    private function scanImageForTrojan(string $content): array
    {
        $patterns = [
            '/<\?php/i' => '检测到PHP代码标签',
            '/<\?=/i' => '检测到PHP短标签输出',
            '/<\s*script/i' => '检测到script标签',
            '/<\s*iframe/i' => '检测到iframe标签',
            '/<\s*object/i' => '检测到object标签',
            '/<\s*embed/i' => '检测到embed标签',
            '/eval\s*\(\s*\$_(get|post|request|cookie)/i' => '检测到一句话木马',
            '/assert\s*\(\s*\$_(get|post|request|cookie)/i' => '检测到一句话木马',
            '/system\s*\(\s*\$_(get|post|request|cookie)/i' => '检测到系统命令执行',
            '/exec\s*\(\s*\$_(get|post|request|cookie)/i' => '检测到系统命令执行',
            '/shell_exec\s*\(\s*\$_(get|post|request|cookie)/i' => '检测到系统命令执行',
            '/passthru\s*\(\s*\$_(get|post|request|cookie)/i' => '检测到系统命令执行',
            '/eval\s*\(\s*(base64_decode|gzinflate|gzuncompress|str_rot13|strrev|convert_uudecode)\s*\(/i' => '检测到编码绕过木马',
            '/assert\s*\(\s*(base64_decode|gzinflate|gzuncompress|str_rot13|strrev|convert_uudecode)\s*\(/i' => '检测到编码绕过木马',
            '/call_user_func\s*\(\s*["\']?(eval|assert|system|exec|shell_exec|passthru)/i' => '检测到回调函数木马',
            '/create_function\s*\(/i' => '检测到create_function木马',
            '/\$_(get|post|request|cookie|server|files)\s*\[/i' => '检测到超全局变量引用',
            '/file_put_contents\s*\(\s*\$_(get|post|request)/i' => '检测到文件写入木马',
            '/fwrite\s*\(\s*\$_(get|post|request)/i' => '检测到文件写入木马',
            '/move_uploaded_file\s*\(\s*\$_(get|post|request)/i' => '检测到文件移动木马',
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
     * 图片重编码（剥离EXIF和嵌入数据）
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
            case 'webp':
                $img = @imagecreatefromwebp($tmpPath);
                if ($img === false) { @unlink($newTmp); return false; }
                $result = imagewebp($img, $newTmp, 90);
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
}
