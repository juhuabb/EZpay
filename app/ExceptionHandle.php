<?php
declare(strict_types=1);

namespace app;

use think\db\exception\DataNotFoundException;
use think\db\exception\ModelNotFoundException;
use think\exception\Handle;
use think\exception\HttpException;
use think\exception\HttpResponseException;
use think\exception\ValidateException;
use think\Response;

class ExceptionHandle extends Handle
{
    /**
     * 不需要记录信息（日志）的异常类列表
     */
    protected $ignoreReport = [
        HttpException::class,
        HttpResponseException::class,
        ModelNotFoundException::class,
        DataNotFoundException::class,
        ValidateException::class,
    ];

    /**
     * 记录异常信息（包括日志或者其它方式记录）
     */
    public function report(\Throwable $exception): void
    {
        parent::report($exception);
    }

    /**
     * Render an exception into an HTTP response.
     */
    public function render($request, \Throwable $e): Response
    {
        // 参数验证错误
        if ($e instanceof ValidateException) {
            return json([
                'code' => 400,
                'msg' => $e->getMessage(),
                'data' => null,
            ], 400);
        }

        // 请求异常
        if ($e instanceof HttpException) {
            return json([
                'code' => $e->getStatusCode(),
                'msg' => $e->getMessage() ?: 'HTTP Error',
                'data' => null,
            ], $e->getStatusCode());
        }

        // 其他错误
        if (env('APP_DEBUG', false)) {
            return parent::render($request, $e);
        }

        return json([
            'code' => 500,
            'msg' => '系统内部错误',
            'data' => null,
        ], 500);
    }
}
