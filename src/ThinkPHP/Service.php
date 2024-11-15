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

namespace Ripple\Driver\ThinkPHP;

use Ripple\Worker\Manager;
use think\Service as ThinkPHPService;

/**
 * @Author cclilshy
 * @Date   2024/8/17 18:20
 */
class Service extends ThinkPHPService
{
    /**
     * @Author cclilshy
     * @Date   2024/8/17 18:20
     * @return void
     */
    public function register(): void
    {
        // 注册服务管理器
        $this->app->bind(Manager::class, fn () => new Manager());

        // 注册终端
        $this->commands([
            'ripple:server' => Driver::class
        ]);
    }
}
