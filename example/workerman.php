<?php
include_once __DIR__ . '/../vendor/autoload.php';

use GuzzleHttp\Promise\Promise;
use Psc\Core\Output;
use Psc\Drive\Workerman;
use Workerman\Timer;
use Workerman\Worker;
use function P\async;
use function P\await;
use function P\delay;

$worker                = new Worker('tcp://127.0.0.1:28008');
$worker->onWorkerStart = function () {
    $timerId = Timer::add(0.1, function () {
        Output::info("memory usage: " . memory_get_usage());
    });


    $timerId2 = Timer::add(1, function () {
        Output::info("memory usage: " . memory_get_usage());
        gc_collect_cycles();
    });

    delay(3, fn() => Timer::del($timerId));
};

$worker->onMessage = function ($connection, $data) {
    //方式1
    async(function () use ($connection) {
        \P\sleep(3);

        $fileContent = await(
        /**
         * @return Promise<string>
         */
            P\IO::File()->getContents(__FILE__)
        );

        $hash = hash('sha256', $fileContent);
        $connection->send("[await] File content hash: {$hash}" . PHP_EOL);
    });

    //使用原生guzzle实现异步请求
    P\Net::Http()->Guzzle()->getAsync('https://www.baidu.com/')
        ->then(function ($response) use ($connection) {
            $connection->send("[async] Response status code: {$response->getStatusCode()}" . PHP_EOL);
        })
        ->except(function (Exception $e) use ($connection) {
            $connection->send("[async] Exception: {$e->getMessage()}" . PHP_EOL);
        });

    $connection->send("say {$data}");
};

Worker::$eventLoopClass = Workerman::class;
Worker::runAll();
