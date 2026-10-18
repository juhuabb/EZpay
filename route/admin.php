<?php
declare(strict_types=1);

use think\facade\Route;

// ==================== 管理后台API路由 ====================
// 注意：后台管理功能实际通过 IndexController（Session认证）实现
// 以下 admin.* 命名空间的路由引用了不存在的控制器类，已注释禁用
// 如需启用 JWT 认证的后台 API，需先创建对应控制器

// 公开路由
Route::group('admin', function () {
    Route::post('auth/login', 'admin.Auth/login');
   

})->middleware(\app\middleware\CorsMiddleware::class);

// 需要管理员认证的路由
Route::group('admin', function () {
    // 认证（admin\Auth 控制器存在）
    Route::post('auth/logout', 'admin.Auth/logout');
    Route::get('auth/profile', 'admin.Auth/profile');

    // 内容管理（admin\ContentManage 控制器存在）
    Route::get('content/list', 'admin.ContentManage/list');
    Route::post('content/create', 'admin.ContentManage/create');
    Route::post('content/:id/update', 'admin.ContentManage/update');
    Route::post('content/:id/delete', 'admin.ContentManage/delete');

    // 投诉管理（admin\ComplaintManage 控制器存在，但实际通过 IndexController 实现）
    // Route::get('complaints', 'admin.ComplaintManage/list');
    // Route::get('complaints/:id', 'admin.ComplaintManage/detail');
    // Route::post('complaints/:id/reply', 'admin.ComplaintManage/reply');
    // Route::post('complaints/:id/resolve', 'admin.ComplaintManage/resolve');

    // === 以下路由引用的控制器不存在，已禁用 ===
    // 实际功能通过 IndexController（Session认证）实现：
    //   /index/adminUserListData      → 用户列表
    //   /index/adminUserAdjustBalance → 调整余额
    //   /index/adminUserResetPassword → 重置密码
    //   /index/adminUserDisable       → 禁用用户
    //   /index/adminUserEnable        → 启用用户
    //   /index/adminOrderListData     → 订单列表
    //   /index/adminForceCloseOrder   → 强制关闭订单
    //   /index/adminTransactionListData → 交易列表
    //   /index/adminVerifyListData    → 认证审核列表
    //   /index/reviewVerify           → 认证审核
    //   /index/adminMerchantListData  → 商户列表
    //   /index/adminMerchantAudit     → 商户审核
    //   /index/adminSystemSettingsSave → 系统设置保存

    // 系统设置（admin\System 不存在）
    // Route::get('system/settings', 'admin.System/getSettings');
    // Route::put('system/settings', 'admin.System/updateSettings');

    // 用户管理（admin\UserManage 不存在）
    // Route::get('users', 'admin.UserManage/list');
    // Route::get('users/:id', 'admin.UserManage/detail');
    // Route::post('users/:id/balance', 'admin.UserManage/adjustBalance');
    // Route::post('users/:id/reset-password', 'admin.UserManage/resetPassword');
    // Route::post('users/:id/disable', 'admin.UserManage/disable');
    // Route::post('users/:id/enable', 'admin.UserManage/enable');

    // 认证审核（admin\VerifyManage 不存在）
    // Route::get('verify/real-name', 'admin.VerifyManage/realNameList');
    // Route::post('verify/real-name/:id/review', 'admin.VerifyManage/realNameReview');
    // Route::get('verify/merchant', 'admin.VerifyManage/merchantList');
    // Route::post('verify/merchant/:id/review', 'admin.VerifyManage/merchantReview');

    // 商户管理
    Route::get('merchants', 'admin.MerchantManage/list');
    Route::get('merchants/:id', 'admin.MerchantManage/detail');
    Route::post('merchants/:id/reset-keys', 'admin.MerchantManage/resetApiKeys');
    Route::post('merchants/:id/toggle-payment', 'admin.MerchantManage/togglePayment');
    Route::post('merchants/:id/payment-limit', 'admin.MerchantManage/setPaymentLimit');

    // 订单管理（admin\OrderManage 不存在）
    // Route::get('orders', 'admin.OrderManage/list');
    // Route::get('orders/:id', 'admin.OrderManage/detail');
    // Route::post('orders/:id/close', 'admin.OrderManage/forceClose');

    // 交易管理（admin\TransactionManage 不存在）
    // Route::get('transactions', 'admin.TransactionManage/list');
    // Route::get('transactions/:id', 'admin.TransactionManage/detail');
    // Route::post('transactions/:id/refund', 'admin.TransactionManage/refund');
})->middleware([\app\middleware\CorsMiddleware::class, \app\middleware\AdminAuth::class]);
