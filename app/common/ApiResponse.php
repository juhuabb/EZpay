<?php
declare(strict_types=1);

namespace app\common;

use think\Response;

class ApiResponse
{
    const CODE_SUCCESS = 0;
    const CODE_PARAM_ERROR = 400;
    const CODE_UNAUTHORIZED = 401;
    const CODE_FORBIDDEN = 403;
    const CODE_NOT_FOUND = 404;
    const CODE_SERVER_ERROR = 500;

    /**
     * 成功响应
     */
    public static function success($data = null, string $msg = 'success'): Response
    {
        return json([
            'code' => self::CODE_SUCCESS,
            'msg' => $msg,
            'data' => $data,
        ]);
    }

    /**
     * 错误响应
     */
    public static function error(string $msg = 'error', int $code = self::CODE_PARAM_ERROR, $data = null): Response
    {
        return json([
            'code' => $code,
            'msg' => $msg,
            'data' => $data,
        ]);
    }

    /**
     * 分页响应
     */
    public static function paginate($list, int $total, int $page = 1, int $pageSize = 20): Response
    {
        return self::success([
            'list' => $list,
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
            'total_pages' => ceil($total / max($pageSize, 1)),
        ]);
    }
}
