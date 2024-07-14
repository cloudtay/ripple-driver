### 安装

```bash
composer require cclilshy/p-ripple-drive
```

### 配置

#### workerman 使用方法

```php
Worker::$eventLoopClass = Workerman::class;
Worker::runAll();
```

#### webman 使用方法

> 修改配置文件config/server.php服务配置文件

```php
return [
    //...
    'event_loop' => \Psc\Drive\Workerman::class,
];
```

#### laravel 使用方法

> 无需修改任何原代码即可使用

```bash
#安装
composer require cclilshy/p-ripple-drive

#运行
php artisan p:run

# -l | --listen     服务监听地址,默认为 http://127.0.0.1:8008
# -t | --threads    服务线程数,默认为4
```

> 你需要有一定了解CLI运行模式的机制,并知悉下列函数在运行过程中会发生什么以决定如何使用它们?如
> `dd` `var_dump` `echo` `exit` `die`, 另外在该运行模式下一般请求无法直接访问public路径下的文件
> 你可以使用原始的方式配置代理到public路径或自行创建路由解决这一需求, laravel提供了文件下载的方式,可以参考:
>

```php
Route::get('/download', function (Request $request) {
    return new BinaryFileResponse(__FILE__);
});
```

> open `http://127.0.0.1:8008/`

#### thinkphp 使用方法

```bash
#安装
composer require cclilshy/p-ripple-drive

#运行
php think p:run

# -l | --listen     服务监听地址,默认为 http://127.0.0.1:8008
# -t | --threads    服务线程数,默认为4
```

> open `http://127.0.0.1:8008/`
---

### 快速上手

> 以下代码为使用示例,最新文档请移步[《使用文档》](https://github.com/cloudtay/p-ripple-core.git)

```php
use GuzzleHttp\Psr7\Response;
use P\Net\WebSocket\Connection;
use function P\async;
use function P\await;
use function P\repeat;
use function P\run;

# 支持库使用例子
P\IO::File();
P\IO::File()->getContents(__FILE__);
P\IO::Socket()->streamSocketClient('tcp://www.baidu.com:80');
P\IO::Socket()->streamSocketClientSSL('tcp://www.baidu.com:443');
P\Net::Http();
P\Net::Http()->Guzzle();
P\Net::Http()->Guzzle()->getAsync('https://www.baidu.com/404');

# 效果展示
// TODO: 异步发起100个请求,方式1
for ($i = 0; $i < 100; $i++) {
    P\Net::Http()->Guzzle()->getAsync('https://www.baidu.com/')->then(function (Response $response) {
        echo "[async] Response status code: {$response->getStatusCode()}" . PHP_EOL;
    })->except(function (Exception $e) {
        echo "[async] Exception: {$e->getMessage()}" . PHP_EOL;
    });
}

// TODO: 异步发起100个请求,方式2
for ($i = 0; $i < 100; $i++) {
    async(function () {
        try {
            $response = await(P\Net::Http()->Guzzle()->getAsync('https://www.baidu.com/'));
            echo "[await] Response status code: {$response->getStatusCode()}" . PHP_EOL;
        } catch (Throwable $exception) {
            echo "[await] Exception: {$exception->getMessage()}" . PHP_EOL;
        }
    });
}

// TODO: 异步读取文件内容
async(function () {
    $fileContent = await(
        P\IO::File()->getContents(__FILE__)
    );

    $hash = hash('sha256', $fileContent);
    echo "[await] File content hash: {$hash}" . PHP_EOL;
});

// TODO: WebSocket 链接例子
$connection         = P\Net::Websocket()->connect('wss://127.0.0.1:8001/wss');
$connection->onOpen = function (Connection $connection) {
    $connection->send('{"action":"sub","data":{"channel":"market:panel@8"}}');

    $timerId = repeat(10, function () use ($connection) {
        $connection->send('{"action":"ping","data":{}}');
    });

    $connection->onClose = function (Connection $connection) use ($timerId) {
        P\cancel($timerId);
    };

    $connection->onMessage = function (string $message, int $opcode, Connection $connection) {
        echo "receive: $message\n";
    };
};

$connection->onError = function (Throwable $throwable) {
    echo "error: {$throwable->getMessage()}\n";
};

run();
```
