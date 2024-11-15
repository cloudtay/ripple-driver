<?php declare(strict_types=1);

/**
 * Copyright © 2024 cclilshy
 * Email: jingnigg@gmail.com
 *
 * This software is licensed under the MIT License.
 * For full license details, please visit: https://opensource.org/licenses/MIT
 *
 * By using this software, you agree to the terms of the license.
 * Contributions, suggestions, and feedback are always welcome!
 */

use Composer\Autoload\ClassLoader;
use Ripple\Driver\Laravel\Coroutine\ContainerMap;

if (!\function_exists('app')) {
    $composer = new ReflectionClass(ClassLoader::class);
    $rootPath = \dirname($composer->getFileName(), 3);
    if (false) {
        // @codeCoverageIgnoreStart
    } elseif (\file_exists("{$rootPath}/artisan")) {
        /**
         * @param string|null $abstract
         * @param array       $parameters
         *
         * @return \Closure|\Illuminate\Container\Container|mixed|object|null
         * @throws \Illuminate\Contracts\Container\BindingResolutionException
         */
        function app(string $abstract = null, array $parameters = []): mixed
        {
            return ContainerMap::app($abstract, $parameters);
        }
    } elseif (\file_exists("{$rootPath}/think")) {
        if (!\function_exists('app')) {
            /**
             * 快速获取容器中的实例 支持依赖注入
             * @template T
             *
             * @param string|class-string<T> $name        类名或标识 默认获取当前应用实例
             * @param array                  $args        参数
             * @param bool                   $newInstance 是否每次创建新的实例
             *
             * @return T|object|\think\App
             */
        }
    }
}
