<?php declare(strict_types=1);

namespace Psc\Drive;

use Psc\Drive\ThinkPHP\PDrive;
use think\Service;

/**
 * ThinkPHP服务容器
 */
class ThinkPHPService extends Service
{
    public function register(): void
    {
        $this->commands([
            'p:run' => PDrive::class
        ]);
    }
}
