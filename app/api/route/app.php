<?php

use think\facade\Route;

// 无需认证的路由
Route::group('', function () {
    Route::post('auth/login', 'Auth/login');
    Route::post('auth/register', 'Auth/register');
    Route::post('auth/refresh', 'Auth/refreshToken');
    Route::get('auth/register-mode', 'Auth/registerMode');
    Route::post('auth/send-sms-code', 'Auth/sendSmsCode');
    Route::post('auth/send-email-code', 'Auth/sendEmailCode');
    Route::post('auth/forgot/send-code', 'Auth/forgotSendCode');
    Route::post('auth/forgot/verify', 'Auth/forgotVerify');
    Route::post('auth/forgot/reset', 'Auth/forgotReset');


    Route::get('index/home', 'Index/homeData');
    Route::get('index/faq', 'Index/faqList');

    // APP热更新版本检查
    Route::get('version/check', 'Index/checkVersion');

    // 用户协议/隐私政策（公开接口）
    Route::get('agreement', 'Index/getAgreement');
    
    Route::get('announcements', 'Announcement/list')->completeMatch(true);
    Route::get('announcements/:id', 'Announcement/detail');

    Route::get('config/trade', 'Index/tradeConfig');

    // 充值配置(无需登录)
    Route::get('recharge/config', 'Recharge/config');

    // 首页广告位(无需登录)
    Route::get('banners', 'Index/banners');
        // 公共配置（游戏开关等，无需登录）
    Route::get('index/config', 'Index/publicConfig');

    // 支付订单信息（公开接口，用户查看支付详情）
    Route::get('pay/info', 'Pay/info');

})->middleware([\app\middleware\MaintenanceMiddleware::class, \app\middleware\CorsMiddleware::class]);

// 需要认证的路由
Route::group('', function () {
    Route::post('auth/logout', 'Auth/logout');

    // 用户相关
    Route::get('user/profile', 'User/profile');
    Route::put('user/profile', 'User/updateProfile');
    Route::put('user/password', 'User/changePassword');
    Route::put('user/pay-password', 'User/changePayPassword');
    Route::post('user/send-reset-pay-code', 'User/sendResetPayPasswordCode');
    Route::post('user/reset-pay-password', 'User/resetPayPassword');
    Route::get('user/contact-info', 'User/getContactInfo');
    Route::post('user/send-bind-code', 'User/sendBindCode');
    Route::post('user/bind-contact', 'User/bindContact');
    Route::get('user/balance', 'User/balance');
        Route::post('user/transfer-bonus', 'User/transferBonus');
    Route::get('user/balance-log', 'User/balanceLog');

    // 实名认证
    Route::post('verify/real-name', 'Verify/submitRealName');
    Route::get('verify/real-name/status', 'Verify/verifyStatus');
    Route::post('verify/merchant', 'Verify/submitMerchant');
    Route::get('verify/merchant/status', 'Verify/merchantStatus');

    // 订单（挂单）
    Route::post('orders', 'Order/createOrder');
    Route::put('orders/:id', 'Order/updateOrder');
    Route::get('market', 'Order/marketList');
    Route::get('orders/my', 'Order/myOrders');
    Route::post('order/delete', 'Order/softDelete');
    Route::get('order/payment-methods/:id', 'Order/orderPaymentMethods');
        Route::post('transaction/cancel', 'Transaction/cancelTransaction');
    Route::get('order/list', 'Order/myOrders');
    Route::get('market/list', 'Market/index');
    Route::get('order/detail/:id', 'Order/orderDetail');

    // 前端兼容路由
    Route::post('order/create', 'Order/createOrder');
    Route::get('market/list', 'Order/marketList');
    Route::get('order/detail/:id', 'Order/orderDetail');
    Route::post('order/buy', 'Transaction/createTransaction');
    Route::post('order/cancel', 'Order/deleteOrder');

    // 上传
    Route::post('upload/image', 'Upload/image');
    Route::post('upload/avatar', 'Upload/avatar');

    // 交易
    Route::post('transactions', 'Transaction/createTransaction');
    Route::get('transactions/my', 'Transaction/myTransactions');
    Route::get('transactions/:id', 'Transaction/transactionDetail');
    Route::post('order/lock', 'Order/lockOrder');
    Route::post('order/unlock', 'Order/unlockOrder');
    Route::get('order/status/:id', 'Order/checkOrderStatus');

    // 收款方式（旧路径兼容，引用app\controller\api\PaymentController）
    Route::get('payments', '\\app\\controller\\api\\Payment/listPaymentMethods');
    Route::post('payments', '\\app\\controller\\api\\Payment/addPaymentMethod');
    Route::put('payments/:id', '\\app\\controller\\api\\Payment/updatePaymentMethod');
    Route::delete('payments/:id', '\\app\\controller\\api\\Payment/deletePaymentMethod');

    // 商户相关
    Route::get('merchant/dashboard', 'Merchant/merchantDashboard');
    Route::get('merchant/credentials', 'Merchant/apiCredentials');
    Route::post('merchant/reset-keys', 'Merchant/resetApiKeys');
    Route::post('merchant/reset-rsa', 'Merchant/resetRsaKeys');
    Route::get('merchant/transactions', 'Merchant/merchantTransactions');
    Route::get('payment/methods', 'PaymentMethod/myMethods');

    Route::post('transaction/confirm-payment', 'Transaction/confirmPayment');
    Route::post('transaction/confirm-receipt', 'Transaction/confirmReceipt');
    Route::post('transaction/reject-payment', 'Transaction/rejectPayment');
    Route::post('transaction/soft-delete', 'Transaction/softDelete');
    //站内信，3条
    Route::get('messages/unread-count', 'Message/unreadCount');
    Route::get('messages', 'Message/messageList');
    Route::post('messages/read', 'Message/markRead');


    // 投诉
    Route::post('complaint/create', 'Complaint/create');
    Route::post('complaint/upload', 'Complaint/uploadProof');
    Route::post('complaint/:id/reply', 'Complaint/reply');
    Route::get('complaint/my', 'Complaint/myComplaints');
    Route::get('complaint/:id', 'Complaint/detail');

    // 商户收款方式
    Route::get('merchant/payment-methods', 'Merchant/paymentMethods');
    Route::post('merchant/payment-methods', 'Merchant/addPaymentMethod');
    Route::put('merchant/payment-methods/:id', 'Merchant/updatePaymentMethod');
    Route::delete('merchant/payment-methods/:id', 'Merchant/deletePaymentMethod');
    // 安全上传收款码图片（含木马检测）
    Route::post('merchant/upload-qrcode', 'Merchant/uploadQrcode');

    // 钱包转账
    Route::post('wallet/transfer', 'Transfer/transfer');
    Route::get('wallet/transfer-list', 'Transfer/transferList');

    // TRC20充值
    Route::post('recharge/create', 'Recharge/create');
    Route::get('recharge/check', 'Recharge/check');
    Route::post('recharge/cancel', 'Recharge/cancel');
    Route::get('recharge/history', 'Recharge/history');

    // 商户支付平台（PayController，避免与原PaymentController冲突）
    Route::get('pay/credentials', 'Pay/credentials');
    Route::post('pay/confirm', 'Pay/confirm');
    Route::get('pay/orders', 'Pay/orders');
    Route::get('pay/stats', 'Pay/stats');
    Route::get('pay/daily-stats', 'Pay/dailyStats');
    Route::get('pay/platform-key', 'Pay/platformKey');
    Route::get('pay/my-payments', 'Pay/myPayments');
    
    Route::post('customer-message/create', 'CustomerMessage/create');
Route::get('customer-message/list', 'CustomerMessage/list');
Route::post('customer-message/close', 'CustomerMessage/close');

    // 在线客服（用户端）
    Route::post('chat/start', 'Chat/start');
    Route::post('chat/send', 'Chat/send');
    Route::get('chat/messages', 'Chat/messages');
    Route::post('chat/upload-image', 'Chat/uploadImage');
    Route::get('chat/session', 'Chat/sessionInfo');
    Route::post('chat/close', 'Chat/close');
})->middleware([\app\middleware\MaintenanceMiddleware::class, \app\middleware\CorsMiddleware::class, \app\middleware\JwtAuth::class]);

// 商户支付API路由（使用商户API认证）
Route::group('merchant', function () {
    Route::post('pay', 'MerchantApi/pay');
    Route::post('query', 'MerchantApi/query');
    Route::post('refund', 'MerchantApi/refund');
})->middleware([\app\middleware\MaintenanceMiddleware::class, \app\middleware\CorsMiddleware::class, \app\middleware\MerchantApiAuth::class]);

    



// 游戏中心路由
Route::group('game', function () {
    Route::get('list', 'Game/list');
    Route::get('config', 'Game/config');
    Route::post('play', 'Game/play');
    Route::get('records', 'Game/records');
})->middleware([\app\middleware\MaintenanceMiddleware::class, \app\middleware\CorsMiddleware::class, \app\middleware\JwtAuth::class]);


// 积分宝路由（需登录）
Route::group('savings', function () {
    Route::get('config', 'Savings/config');
    Route::get('projects', 'Savings/projects');
    Route::get('projects/:id', 'Savings/projectDetail');
    Route::get('summary', 'Savings/summary');
    Route::get('orders', 'Savings/myOrders');
    Route::post('purchase', 'Savings/purchase');
    Route::post('redeem', 'Savings/redeem');
})->middleware([\app\middleware\MaintenanceMiddleware::class, \app\middleware\CorsMiddleware::class, \app\middleware\JwtAuth::class]);
