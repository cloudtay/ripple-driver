<?php declare(strict_types=1);

namespace Psc\Drive\ThinkPHP\Middleware;

use Closure;
use think\Request;

use function app;

class IsolationMiddleware
{
    /**
     * 处理请求
     *
     * @param Request $request
     * @param Closure $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next): mixed
    {
        if ($controllerClassName = $request->controller()) {
            app()->bind($controllerClassName, static function () use ($controllerClassName) {
                return app()->make($controllerClassName);
            });
        }
        return $next($request);
    }
}
