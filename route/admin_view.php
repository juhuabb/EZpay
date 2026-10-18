<?php
declare(strict_types=1);

use think\facade\Route;

// ==================== 后台管理视图页面路由 ====================
// 注意：视图页面统一使用 /admin/view/ 前缀，避免与API路由（admin.php）冲突

// 登录页面（不需要认证，但需要Session）
Route::get('admin/login', 'admin.view.Admin/login')
    ->middleware(\think\middleware\SessionInit::class);

// 需要Session认证的后台页面（使用 /admin/view/ 前缀）
Route::group('admin/view', function () {
    Route::get('', 'admin.view.Admin/index');
    Route::get('api-token', 'admin.view.Admin/apiToken');
    Route::get('logout', 'admin.view.Admin/logout');
    Route::get('dashboard', 'admin.view.Admin/dashboard');
    Route::get('system/settings', 'admin.view.Admin/systemSettings');
    Route::get('users', 'admin.view.Admin/userList');
    Route::get('users/:id', 'admin.view.Admin/userDetail');
    Route::get('verify', 'admin.view.Admin/verifyList');
    Route::get('verify/:id', 'admin.view.Admin/verifyDetail');
    Route::get('merchants', 'admin.view.Admin/merchantList');
    Route::get('merchants/:id', 'admin.view.Admin/merchantDetail');
    Route::get('orders', 'admin.view.Admin/orderList');
    Route::get('orders/:id', 'admin.view.Admin/orderDetail');
    Route::get('transactions', 'admin.view.Admin/transactionList');
    Route::get('transactions/:id', 'admin.view.Admin/transactionDetail');
    Route::get('adminContentList', 'admin.view.Admin/contentList');
    
    
    
})->middleware(\think\middleware\SessionInit::class)
  ->middleware(\app\middleware\AdminViewAuth::class);
