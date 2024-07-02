### Install

```bash
composer require workerman/workerman
composer require cclilshy/p-ripple-drive
```

### Example

```php
<?php
include_once __DIR__ . '/vendor/autoload.php';

use Cclilshy\PRippleDrive\WorkerMan;
use Cclilshy\PRippleEvent\Core\Coroutine\Promise;
use Cclilshy\PRippleEvent\Facades\Guzzle;
use Workerman\Timer;
use Workerman\Worker;
use function A\async;
use function A\await;
use function A\delay;
use function A\fileGetContents;

$worker                = new Worker('tcp://127.0.0.1:28008');
$worker->onWorkerStart = function () {
    $timerId = Timer::add(0.1, function () {
        echo "hello world\n";
    });

    delay(3, fn() => Timer::del($timerId));
};

$worker->onMessage = function ($connection, $data) {
    //方式1
    async(function () use ($connection) {
        \A\sleep(3);

        $fileContent = await(
            /**
             * @return Promise<string>
             */
            fileGetContents(__FILE__)
        );
        
        $hash        = hash('sha256', $fileContent);
        $connection->send("[await] File content hash: {$hash}" . PHP_EOL);
    });

    //使用原生guzzle实现异步请求
    Guzzle::getAsync('https://www.baidu.com/')
        ->then(function ($response) use ($connection) {
            $connection->send("[async] Response status code: {$response->getStatusCode()}" . PHP_EOL);
        })
        ->except(function (Exception $e) use ($connection) {
            $connection->send("[async] Exception: {$e->getMessage()}" . PHP_EOL);
        });

    $connection->send("say {$data}");
};

Worker::$eventLoopClass = WorkerMan::class;
Worker::runAll();

```
