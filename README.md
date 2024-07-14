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

---

> ⚠️ 在`Laravel`,`ThinkPHP下`的CLI模式, 整个运行过程的 `Controller` `Service`
> 等 `Container::make` `Container::getInstance()` 构建的单例
> 只会被构造一次, 意味着如果你使用 `Controller::__construct(Request $request)`
> 的方式构造了一个控制器将导致全局的`Controller->request`将访问到初次构造的请求对象,
> 因此在开发过程应特别关心这一点, Request应该具体到某个Action的参数上以确保线程安全,
> CLI模式在这点上与FPM截然不同, 但这也是它能够拥有火箭般速度的原因之一

#### laravel 使用方法

> 无需修改任何原代码即可使用,你需要有一定了解CLI运行模式的机制,并知悉下列函数在运行过程中会发生什么以决定如何使用它们?如
> `dd` `var_dump` `echo` `exit` `die`,

```bash
#安装
composer require cclilshy/p-ripple-drive

#运行
php artisan p:run

# -l | --listen     服务监听地址,默认为 http://127.0.0.1:8008
# -t | --threads    服务线程数,默认为4
```

##### 异步文件下载

```php
Route::get('/download', function (Request $request) {
    return new BinaryFileResponse(__FILE__);
});
```

---

> 在CLI运行模式下一般请求无法直接访问public路径下的文件
> 你可以通过原始的方式配置代理到public路径或自行创建路由解决这一需求

##### 解决方案(Nginx代理,推荐)

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

##### 解决方案(独立运行)

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

最新文档请移步 [《使用文档》](https://github.com/cloudtay/p-ripple-core.git)
