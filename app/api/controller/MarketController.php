<?php
declare(strict_types=1);
namespace app\api\controller;
use think\facade\Db;

class MarketController
{
    public function index()
    {
        $page = (int)(request()->get('page', 1));
        $pageSize = 20;
        $search = trim(request()->get('search', ''));
        $sort = request()->get('sort', 'latest');
        $minPrice = request()->get('min_price', '');
        $maxPrice = request()->get('max_price', '');

        $query = Db::name('orders')
    ->alias('o')
    ->leftJoin('users u', 'u.id = o.user_id')
    ->where('o.status', 1)
    ->where('o.remaining_amount', '>', 0);


        if ($search !== '') {
            $query->where(function($q) use ($search) {
                $q->where('o.title', 'like', '%' . $search . '%')
                  ->whereOr('u.nickname', 'like', '%' . $search . '%');
            });
        }

        if ($minPrice !== '') {
            $query->where('o.unit_price', '>=', floatval($minPrice));
        }
        if ($maxPrice !== '') {
            $query->where('o.unit_price', '<=', floatval($maxPrice));
        }

        switch ($sort) {
            case 'price_asc':
                $query->order('o.unit_price', 'asc');
                break;
            case 'price_desc':
                $query->order('o.unit_price', 'desc');
                break;
            case 'amount_desc':
                $query->order('o.remaining_amount', 'desc');
                break;
            default:
                $query->order('o.created_at', 'desc');
        }

        $list = $query->field([
                'o.id',
                'o.title',
                'o.points_amount',
                'o.remaining_amount',
                'o.unit_price',
                'o.total_amount',
                'o.fee_amount',
                'o.created_at',
                'u.nickname as seller_name'

            ])
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        $total = Db::name('orders')
            ->where('status', 1)
            ->where('remaining_amount', '>', 0)
            ->count();

        return json([
            'code' => 0,
            'msg' => 'success',
            'data' => [
                'list' => $list,
                'total' => $total,
                'page' => $page
            ]
        ]);
    }
}
