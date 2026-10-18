<?php
declare(strict_types=1);
namespace app\controller\api;
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
        $announcements = Announcement::visible()
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

    public function simpleTest()
    {
        return json(['msg' => 'CONTROLLER_ROUTE_OK']);
    }
}
