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

最新文档请移步 [《使用文档》](https://github.com/cloudtay/p-ripple-core.git)
