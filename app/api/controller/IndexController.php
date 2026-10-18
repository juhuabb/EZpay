<?php
declare(strict_types=1);

namespace app\api\controller;

use app\model\Announcement;
use app\model\Order;
use app\model\SystemSetting;
use app\model\Transaction;
use think\Request;
use think\Response;

class IndexController
{
    /**
     * 首页数据
     */
    public function homeData(Request $request): Response
    {
        // 获取最新公告
        $announcements = Announcement::order('created_at', 'desc')
            ->ordered()
            ->limit(5)
            ->field('id,title,created_at')
            ->select();

        // 市场统计数据
        $totalListed = Order::where('status', 1)
            ->where('remaining_amount', '>', 0)
            ->sum('remaining_amount');
        $activeOrders = Order::where('status', 1)
            ->where('remaining_amount', '>', 0)
            ->count();
        $todayTrades = Transaction::where('status', 2)
            ->whereDay('created_at')
            ->count();
        $todayVolume = Transaction::where('status', 2)
            ->whereDay('created_at')
            ->sum('total_price');

        // 最低价格
        $lowestPrice = Order::where('status', 1)
            ->where('remaining_amount', '>', 0)
            ->min('unit_price');

        return json([
            'code' => 0,
            'msg' => 'success',
            'data' => [
                'announcements' => $announcements,
                'market_stats' => [
                    'total_listed' => (float) $totalListed,
                    'active_orders' => $activeOrders,
                    'today_trades' => $todayTrades,
                    'today_volume' => (float) $todayVolume,
                    'lowest_price' => (float) ($lowestPrice ?: 0),
                ],
                'site_name' => SystemSetting::getValue('site_name', '积分交易系统'),
            ]
        ]);
    }

    /**
     * FAQ列表
     */
    public function faqList(Request $request): Response
    {
        $faqs = [
            [
                'q' => '如何注册账户？',
                'a' => '点击注册按钮，输入用户名、手机号和密码即可完成注册。',
            ],
            [
                'q' => '如何进行实名认证？',
                'a' => '登录后进入个人中心，提交身份证正反面照片和真实姓名即可完成实名认证申请。',
            ],
            [
                'q' => '如何成为商户？',
                'a' => '完成实名认证后，可申请商户认证。提交网站地址等信息，审核通过后即可获得商户权限和API密钥。',
            ],
            [
                'q' => '如何购买积分？',
                'a' => '在市场页面选择挂单，输入购买数量，确认支付即可。积分会实时到账。',
            ],
            [
                'q' => '商户如何集成支付API？',
                'a' => '商户认证后会获得API密钥和RSA密钥对。使用API密钥进行身份验证，RSA密钥用于请求签名。详细文档请参阅开发者中心。',
            ],
            [
                'q' => '支付密码忘记了怎么办？',
                'a' => '可以联系管理员重置支付密码，或通过账户安全验证后自行重置。',
            ],
        ];

        return json(['code' => 0, 'msg' => 'success', 'data' => $faqs]);
    }


    /**
     * 公共配置接口（无需登录）
     * GET /index/config
     */
    public function publicConfig()
    {
        try {
            $gameEnabled = \think\facade\Db::name('system_settings')
                ->where('setting_key', 'game_enabled')
                ->value('setting_value');
        } catch (\Exception $e) {
            $gameEnabled = '0';
        }

        return json([
            'code' => 0,
            'msg' => 'success',
            'data' => [
                'game_enabled' => $gameEnabled === '1' ? 1 : 0,
            ]
        ]);
    }


     


    public function tradeConfig() {
    $settings = \think\facade\Db::name('system_settings')
        ->whereIn('setting_key', ['trade_fee_rate', 'trade_min_amount', 'trade_max_amount', 'trade_min_buy', 'trade_buyer_fee_rate'])
        ->select()->toArray();
    $result = [];
    foreach ($settings as $s) {
        $result[$s['setting_key']] = $s['setting_value'];
    }
    // 默认值
    if (!isset($result['trade_fee_rate'])) $result['trade_fee_rate'] = '2';
    if (!isset($result['trade_min_amount'])) $result['trade_min_amount'] = '1';
    if (!isset($result['trade_min_buy'])) $result['trade_min_buy'] = '0';
    if (!isset($result['trade_buyer_fee_rate'])) $result['trade_buyer_fee_rate'] = '0';
    return json(['code' => 0, 'data' => $result]);
}




    /**
     * 首页广告位(公开接口)
     */
    public function banners(): Response
    {
        $list = \think\facade\Db::name('banners')
            ->where('status', 1)
            ->order('sort_order', 'desc')
            ->order('id', 'asc')
            ->field('id,title,image_url,link_url')
            ->select()
            ->toArray();

        // 读取后端网址设置，拼接完整图片URL
        $backendUrl = \think\facade\Db::name('system_settings')
            ->where('setting_key', 'backend_url')
            ->value('setting_value');
        if ($backendUrl) {
            $backendUrl = rtrim($backendUrl, '/');
            foreach ($list as &$item) {
                if (!empty($item['image_url']) && strpos($item['image_url'], 'http') !== 0) {
                    $item['image_url'] = $backendUrl . $item['image_url'];
                }
            }
            unset($item);
        }

        return json(['code' => 0, 'msg' => 'success', 'data' => $list]);
    }

    /**
     * 快速指南
     */
    public function quickGuide(Request $request): Response
    {
        $guide = [
            'steps' => [
                [
                    'step' => 1,
                    'title' => '注册账户',
                    'description' => '使用手机号和用户名注册账户',
                ],
                [
                    'step' => 2,
                    'title' => '实名认证',
                    'description' => '提交身份证信息进行实名认证',
                ],
                [
                    'step' => 3,
                    'title' => '充值余额',
                    'description' => '联系管理员或通过支付充值积分余额',
                ],
                [
                    'step' => 4,
                    'title' => '开始交易',
                    'description' => '在市场选择挂单购买积分，或作为商户挂单出售',
                ],
            ],
            'merchant_steps' => [
                [
                    'step' => 1,
                    'title' => '完成实名认证',
                    'description' => '首先完成个人账户实名认证',
                ],
                [
                    'step' => 2,
                    'title' => '申请商户认证',
                    'description' => '提交商户认证申请，等待审核',
                ],
                [
                    'step' => 3,
                    'title' => '获取API密钥',
                    'description' => '审核通过后获取API密钥和RSA密钥对',
                ],
                [
                    'step' => 4,
                    'title' => '集成支付',
                    'description' => '使用API集成支付功能到您的网站',
                ],
                [
                    'step' => 5,
                    'title' => '挂单出售',
                    'description' => '设置积分出售价格和数量，开始交易',
                ],
            ],
        ];

        return json(['code' => 0, 'msg' => 'success', 'data' => $guide]);
    }

    /**
     * 检查APP版本更新（热更新）
     * GET /version/check?version=1.0.0
     * 返回: {code:0, data:{update:boolean, wgt_url:string, title:string, content:string, force:boolean, version:string}}
     */
    public function checkVersion(Request $request): Response
    {
        $currentVersion = $request->param('version', '');

        // 从数据库读取线上发行版本
        $version = \think\facade\Db::name('app_versions')
            ->where('is_active', 1)
            ->find();

        // 没有线上版本，返回无更新
        if (!$version) {
            return json(['code' => 0, 'msg' => 'success', 'data' => [
                'update' => false,
                'version' => '',
            ]]);
        }

        $latestVersion = $version['version'];
        $wgtFile = $version['wgt_file'] ?? '';

        if (empty($wgtFile)) {
            return json(['code' => 0, 'msg' => 'success', 'data' => [
                'update' => false,
                'version' => $latestVersion,
            ]]);
        }

        // 拼接完整wgt下载URL（用backend_url设置）
        $backendUrl = \think\facade\Db::name('system_settings')
            ->where('setting_key', 'backend_url')
            ->value('setting_value');
        $wgtUrl = rtrim($backendUrl ?: '', '/') . $wgtFile;

        // 比较版本号
        $needUpdate = $this->_compareVersion($latestVersion, $currentVersion) > 0;

        return json(['code' => 0, 'msg' => 'success', 'data' => [
            'update' => $needUpdate,
            'version' => $latestVersion,
            'wgt_url' => $wgtUrl,
            'title' => $version['title'] ?: '发现新版本',
            'content' => $version['content'] ?: '',
            'force' => (bool)($version['force_update'] ?? false),
        ]]);
    }

    /**
     * 版本号比较: 返回 1=v1>v2, 0=相等, -1=v1<v2
     */
    private function _compareVersion(string $v1, string $v2): int
    {
        $parts1 = array_map('intval', explode('.', $v1));
        $parts2 = array_map('intval', explode('.', $v2));
        $maxLen = max(count($parts1), count($parts2));
        $parts1 = array_pad($parts1, $maxLen, 0);
        $parts2 = array_pad($parts2, $maxLen, 0);
        for ($i = 0; $i < $maxLen; $i++) {
            if ($parts1[$i] > $parts2[$i]) return 1;
            if ($parts1[$i] < $parts2[$i]) return -1;
        }
        return 0;
    }

    /**
     * 获取用户协议/隐私政策内容（公开接口）
     * GET /agreement?type=agreement|privacy
     */
    public function getAgreement(Request $request): Response
    {
        $type = $request->param('type', 'agreement');
        $key = ($type === 'privacy') ? 'privacy_policy' : 'user_agreement';
        $content = \think\facade\Db::name('system_settings')
            ->where('setting_key', $key)
            ->value('setting_value');
        $title = ($type === 'privacy') ? '隐私政策' : '用户协议';
        return json([
            'code' => 0,
            'msg' => 'success',
            'data' => [
                'title' => $title,
                'content' => $content ?: '暂无内容',
            ],
        ]);
    }
}