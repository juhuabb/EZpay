<?php
declare(strict_types=1);

namespace app\controller\admin;

use think\facade\Db;
use think\facade\Request;

class Admin
{
    public function dashboardStats()
    {
        try { $users = Db::name('users')->count(); } catch (\Exception $e) { $users = 0; }
        try { $merchants = Db::name('merchants')->where('status', 1)->count(); } catch (\Exception $e) { $merchants = 0; }
        try { $todayOrders = Db::name('orders')->whereDay('created_at')->count(); } catch (\Exception $e) { $todayOrders = 0; }
        try { $todayTrans = Db::name('transactions')->whereDay('created_at')->count(); } catch (\Exception $e) { $todayTrans = 0; }
        return json(['code' => 0, 'data' => ['users' => $users, 'merchants' => $merchants, 'today_orders' => $todayOrders, 'today_transactions' => $todayTrans]]);
    }
    
    
 


    public function userList()
    {
        $page = Request::param('page', 1);
        $limit = Request::param('limit', 10);
        try { $list = Db::name('users')->page($page, $limit)->select()->toArray(); $total = Db::name('users')->count(); } catch (\Exception $e) { $list = []; $total = 0; }
        return json(['code' => 0, 'data' => ['list' => $list, 'total' => $total]]);
    }

    public function merchantList()
    {
        $page = Request::param('page', 1);
        $limit = Request::param('limit', 10);
        try { $list = Db::name('merchants')->page($page, $limit)->select()->toArray(); $total = Db::name('merchants')->count(); } catch (\Exception $e) { $list = []; $total = 0; }
        return json(['code' => 0, 'data' => ['list' => $list, 'total' => $total]]);
    }

    public function orderList()
    {
        $page = Request::param('page', 1);
        $limit = Request::param('limit', 10);
        try { $list = Db::name('orders')->page($page, $limit)->order('id', 'desc')->select()->toArray(); $total = Db::name('orders')->count(); } catch (\Exception $e) { $list = []; $total = 0; }
        return json(['code' => 0, 'data' => ['list' => $list, 'total' => $total]]);
    }

    public function transactionList()
    {
        $page = Request::param('page', 1);
        $limit = Request::param('limit', 10);
        try { $list = Db::name('transactions')->page($page, $limit)->order('id', 'desc')->select()->toArray(); $total = Db::name('transactions')->count(); } catch (\Exception $e) { $list = []; $total = 0; }
        return json(['code' => 0, 'data' => ['list' => $list, 'total' => $total]]);
    }

    public function realNameList()
    {
        $page = Request::param('page', 1);
        $limit = Request::param('limit', 10);
        try { $list = Db::name('real_name_verify')->page($page, $limit)->order('id', 'desc')->select()->toArray(); $total = Db::name('real_name_verify')->count(); } catch (\Exception $e) { $list = []; $total = 0; }
        return json(['code' => 0, 'data' => ['list' => $list, 'total' => $total]]);
    }

    public function systemSettings()
    {
        return json(['code' => 0, 'msg' => 'ok', 'data' => []]);
    }

    public function updateSettings()
    {
        return json(['code' => 0, 'msg' => '保存成功', 'data' => []]);
    }
}