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

namespace Ripple\Driver\Utils;

use Ripple\File\Monitor;
use Ripple\Utils\Output;
use Ripple\Worker\Manager;
use Ripple\Worker\Worker;

class Guard
{
    /**
     * @param \Ripple\Worker\Manager $manager
     * @param \Ripple\Worker\Worker  $worker
     * @param \Ripple\File\Monitor   $monitor
     *
     * @return void
     */
    public static function relevance(
        Manager $manager,
        Worker  $worker,
        Monitor $monitor
    ): void {
        $monitor->onTouch = function (string $file) use ($manager, $worker) {
            $manager->reload($worker->getName());
            Output::writeln("File {$file} touched");
        };

        $monitor->onModify = function (string $file) use ($manager, $worker) {
            $manager->reload($worker->getName());
            Output::writeln("File {$file} modify");
        };

        $monitor->onRemove = function (string $file) use ($manager, $worker) {
            $manager->reload($worker->getName());
            Output::writeln("File {$file} remove");
        };

        $monitor->run();
    }
}
