## 项目简介

> 该项目为主流PHP框架提供了使用PRipple驱动的能力, 能使得原有项目在不修改项目代码的前提下以CLI模式运行,
> 加速框架的运行速度, 达到编译级性能(约传统FPM的5~10倍+),适用于高并发场景;

> PRipple不会修改与干涉框架的运行机制, 你的代码在PRipple驱动模式下几乎能与PHP-FPM环境一样工作, 但在性能提升上有着巨大的优势,
> 并扩展了多个现代化PHP异步编程能力如你可以在控制器中做这些事:

### 特性 defer

```php
public function index(Request $request) : string 
{
    \P\defer(function(){
        //TODO: 这里的代码将在return之后运行,允许返回请求后做某些事情
    });
    return 'Hello PRipple';
}
```

### 特性 process

> 对于长耗时的应用可以单开一个进程处理, 以避免阻塞主进程, 对于进程的控制可在文章末尾查看更多PRipple用法

```php
public function index(Request $request) : string 
{
    $task = P\System::Process()->task(function(){
        // childProcess
    });
    $task->run();
    
    $runtime = $task->run();                // 返回一个Runtime对象
    $runtime->stop();                       // 取消运行(信号SIGTERM)
    $runtime->stop(true);                   // 强制终止,等同于$runtime->kill()
    $runtime->kill();                       // 强制终止(信号SIGKILL)
    $runtime->signal(SIGTERM);              // 发送信号,提供了更精细的控制手段
    $runtime->then(function($exitCode){});  // 程序正常退出时会触发这里的代码,code为退出码
    $runtime->except(function(){});         // 程序非正常退出时会触发这里的代码,可以在这里处理异常,如进程守护/task重启
    $runtime->finally(function(){});        // 无论程序正常退出还是非正常退出都会触发这里的代码
    $runtime->getProcessId();               // 获取子进程ID
    $runtime->getPromise();                 // 获取Promise对象
}
```

### 特性 await

```php
public function index(Request $request) : string 
{
    // 非堵塞进程模式读取某个文件
    $content = \P\await( 
        \P\IO::File()->getContents(__FILE__) 
    );
    
    return $content;
}
```

### 特性 组件原生化

> PRipple不会干涉组件的规范, 你可以使用任何你喜欢的组件,并得到预期的结果,
> 如以下例子将得到`GuzzleHttp`的标准Response对象

```php
public function index(Request $request) : string 
{
    // 非堵塞进程模式请求某个URL
    $response = \P\await( 
        \P\Net::Http()->Guzzle()->getAsync('http://www.baidu.com') 
    );
    
    return $response;
}

```

### 特性

> Timer / Socket / WebSocket 等更多特性可以在文章末尾中查看更多PRipple用法

## 在项目中安装

> 通过composer安装

```bash
composer require cclilshy/p-ripple-drive
```

## 装配方案参考

### WorkerMan

```php
Worker::$eventLoopClass = Workerman::class;
Worker::runAll();
```

---

### WebMan

> 修改配置文件config/server.php服务配置文件

```php
return [
    //...
    'event_loop' => \Psc\Drive\Workerman::class,
];
```

--- 

### Laravel 使用方法

```bash
#安装
composer require cclilshy/p-ripple-drive

#运行
php artisan p:run

# -l | --listen     服务监听地址,默认为 http://127.0.0.1:8008
# -t | --threads    服务线程数,默认为4
```

#### 访问连接

> 访问 `http://127.0.0.1:8008/

#### 运行效果

![display](https://raw.githubusercontent.com/cloudtay/p-ripple-drive/main/assets/display.jpg)

#### 附) Laravel 异步文件下载使用方法

```php
Route::get('/download', function (Request $request) {
    return new BinaryFileResponse(__FILE__);
});
```

### 静态文件配置

> 传统的FPM项目在CLI运行模式下一般请求无法直接访问public路径下的文件
> 你可以通过Nginx路由方式配置代理到public路径或自行创建路由解决这一需求
> 以下是两种参考解决方案(Laravel)

* 静态文件访问解决方案(Nginx代理)

> 配置Nginx伪静态

```nginx
location / {
    try_files $uri $uri/ @backend;
}

location @backend {
    proxy_pass http://127.0.0.1:8008;
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
}
```

* 静态文件访问解决方案(独立运行)

> 添加Laravel路由项

```php
if (PHP_SAPI === 'cli') {
    Route::where(['path' => '.*'])->get('/{path}', function (Request $request, \Illuminate\Http\Response $response, string $path) {
        $fullPath = public_path($path);
        if (file_exists($fullPath)) {
            $ext = pathinfo($fullPath, PATHINFO_EXTENSION);

            if (strtolower($ext) === 'php') {
                $response->setStatusCode(403);

            } elseif (str_contains(urldecode($fullPath), '..')) {
                $response->setStatusCode(403);

            } else {
                $mimeType = match ($ext) {
                    'css' => 'text/css',
                    'js' => 'application/javascript',
                    'json' => 'application/json',
                    'png' => 'image/png',
                    'jpg', 'jpeg' => 'image/jpeg',
                    'gif' => 'image/gif',
                    'svg' => 'image/svg+xml',
                    'ico' => 'image/x-icon',
                    'mp4' => 'video/mp4',
                    'webm' => 'video/webm',
                    'mp3' => 'audio/mpeg',
                    'wav' => 'audio/wav',
                    'webp' => 'image/webp',
                    'pdf' => 'application/pdf',
                    'zip' => 'application/zip',
                    'rar' => 'application/x-rar-compressed',
                    'tar' => 'application/x-tar',
                    'gz' => 'application/gzip',
                    'bz2' => 'application/x-bzip2',
                    'txt' => 'text/plain',
                    'html', 'htm' => 'text/html',
                    default => 'application/octet-stream',
                };
                $response->headers->set('Content-Type', $mimeType);
                $response->setContent(
                    P\await(P\IO::File()->getContents($fullPath))
                );
            }
            return $response;
        }
        return $response->setStatusCode(404);
    });
}
```

--- 

### ThinkPHP 使用方法

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

### 附) 与传统FPM模式差异

>
> 在`Laravel`,`ThinkPHP下`的CLI模式, 整个运行过程的 `Controller` `Service`
> 等 `Container` 构建的单例,默认只会在运行时被构造一次(全局唯一控制器对象), 且在整个运行过程中不会被销毁
> 在开发过程应特别关心这一点与CLI模式在这点上与FPM截然不同, 但这也是它能够拥有火箭般速度的原因之一

> PRipple不会干涉框架的运行机制, 因此我们为上述场景提供了解决方案以Laravel例,可以创建中间件以在每次请求时重新构建控制器以保证线程安全

```php
//中间件handle部分的代码
$route = $request->route();
if ($route instanceof Route) {
    $route->controller     = app($route->getControllerClass());
}
return $next($request);
```

> 你需要有一定了解CLI运行模式的机制,并知悉下列函数在运行过程中会发生什么以决定如何使用它们?如
> `dd` `var_dump` `echo` `exit` `die`

### 附) PRipple更多使用方法

最新文档请移步 [《使用文档》](https://github.com/cloudtay/p-ripple-core.git)

### 附) 基准测试

#### 基准测试 PRipple

```bash
ab -n 10000 -c 40 -k http://127.0.0.1:8008/ #command

This is ApacheBench, Version 2.3 <$Revision: 1903618 $>
Copyright 1996 Adam Twiss, Zeus Technology Ltd, http://www.zeustech.net/
Licensed to The Apache Software Foundation, http://www.apache.org/

Benchmarking 127.0.0.1 (be patient)
Completed 1000 requests
Completed 2000 requests
Completed 3000 requests
Completed 4000 requests
Completed 5000 requests
Completed 6000 requests
Completed 7000 requests
Completed 8000 requests
Completed 9000 requests
Completed 10000 requests
Finished 10000 requests


Server Software:        
Server Hostname:        127.0.0.1
Server Port:            8008

Document Path:          /
Document Length:        11 bytes

Concurrency Level:      40
Time taken for tests:   6.106 seconds
Complete requests:      10000
Failed requests:        0
Keep-Alive requests:    10000
Total transferred:      11220000 bytes
HTML transferred:       110000 bytes
Requests per second:    1637.70 [#/sec] (mean)
Time per request:       24.424 [ms] (mean)
Time per request:       0.611 [ms] (mean, across all concurrent requests)
Transfer rate:          1794.44 [Kbytes/sec] received

Connection Times (ms)
              min  mean[+/-sd] median   max
Connect:        0    0   0.1      0       2
Processing:     2   24  15.1     20     187
Waiting:        2   24  15.1     20     187
Total:          2   24  15.1     20     187

Percentage of the requests served within a certain time (ms)
  50%     20
  66%     23
  75%     25
  80%     27
  90%     34
  95%     45
  98%     71
  99%     98
 100%    187 (longest request)
```

#### 基准测试 Nginx+FPM 驱动

```bash
ab -n 10000 -c 40 -k http://127.0.0.1:80/ #command

This is ApacheBench, Version 2.3 <$Revision: 1903618 $>
Copyright 1996 Adam Twiss, Zeus Technology Ltd, http://www.zeustech.net/
Licensed to The Apache Software Foundation, http://www.apache.org/

Benchmarking 127.0.0.1 (be patient)
Completed 1000 requests
^C

Server Software:        
Server Hostname:        127.0.0.1
Server Port:            8000

Document Path:          /
Document Length:        11 bytes

Concurrency Level:      40
Time taken for tests:   17.470 seconds
Complete requests:      1558
Failed requests:        0
Keep-Alive requests:    0
Total transferred:      1782352 bytes
HTML transferred:       17138 bytes
Requests per second:    89.18 [#/sec] (mean)
Time per request:       448.532 [ms] (mean)
Time per request:       11.213 [ms] (mean, across all concurrent requests)
Transfer rate:          99.63 [Kbytes/sec] received

Connection Times (ms)
              min  mean[+/-sd] median   max
Connect:        0    0   0.2      0       2
Processing:    12  435  41.3    437     500
Waiting:       11  435  41.3    437     500
Total:         13  436  41.2    437     501

Percentage of the requests served within a certain time (ms)
  50%    437
  66%    441
  75%    444
  80%    445
  90%    457
  95%    467
  98%    476
  99%    487
 100%    501 (longest request)
```

#### 在生产环境中的差异对比

![diff](https://raw.githubusercontent.com/cloudtay/p-ripple-drive/main/assets/diff.jpg)
