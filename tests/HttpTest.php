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

namespace Tests;

use Co\App;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Throwable;

use function Co\cancelAll;
use function Co\wait;
use function intval;

class HttpTest extends TestCase
{
    /**
     * @return void
     * @throws Throwable
     */
    #[Test]
    public function test_http(): void
    {
        $client = App::Guzzle()->newClient();

        for ($i = 0; $i < 100; $i++) {
            $client->get('http://127.0.0.1:8008/memory');
        }

        \Co\sleep(1);

        $base = intval($client->get('http://127.0.0.1:8008/memory')->getBody()->getContents());

        for ($i = 0; $i < 100; $i++) {
            $client->get('http://127.0.0.1:8008/memory');
        }

        \Co\sleep(1);

        $result = intval($client->get('http://127.0.0.1:8008/memory')->getBody()->getContents());

        $this->assertEquals($base, $result);

        cancelAll();

        wait();
    }
}
