> ⚠️ This document is translated by an AI language model and may not be updated in time. Please refer to the original
> text.

### Project Description

> This project provides mainstream PHP frameworks with the ability to use PRipple drivers, enabling original projects to
> run in CLI mode without modifying the project code, accelerating the running speed of the framework.
> Achieve compile-level performance (about 10~40 times), suitable for high concurrency scenarios

### Install in project

> Install via composer

```bash
composer require cclilshy/p-ripple-drive
```

### Assembly plan reference

#### workerman

```php
Worker::$eventLoopClass = Workerman::class;
Worker::runAll();
```

#### webman

> Modify the configuration file config/server.php service configuration file

```php
return [
    //...
    'event_loop' => \Psc\Drive\Workerman::class,
];
```

#### Laravel & ThinkPHP project differences

> ⚠️In the CLI mode of `Laravel`, `ThinkPHP`, the `Controller` `Service` of the entire running process
> The singleton constructed by `Container` will only be constructed once at runtime (a globally unique controller
> object), and will not be destroyed during the entire running process.
> You should pay special attention to this during the development process. Request should be specific to the parameters
> of an Action to ensure thread safety.
> CLI mode is very different from FPM in this regard, but this is one of the reasons why it can be so fast

> ⚠️You need to have a certain understanding of the mechanism of CLI running mode, and know what will happen during the
> running of the following functions to decide how to use them? Such as
> `dd` `var_dump` `echo` `exit` `die`,

#### How to use Laravel

```bash
#Install
composer require cclilshy/p-ripple-drive

#run
php artisan p:run

# -l | --listen Service listening address, the default is http://127.0.0.1:8008
# -t | --threads Number of service threads, default is 4
```

##### Asynchronous file download

```php
Route::get('/download', function (Request $request) {
    return new BinaryFileResponse(__FILE__);
});
```

##### running result

![display](https://raw.githubusercontent.com/cloudtay/p-ripple-drive/main/assets/display.jpg)

##### Benchmark PRipple

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
Completed 10000 requests


Server Software:
Server Hostname: 127.0.0.1
Server Port: 8008

Document Path: /
Document length: 11 bytes

Concurrency Level: 40
Time taken for tests: 6.106 seconds
Complete requests: 10000
Failed requests: 0
Keep-Alive requests: 10000
Total transferred: 11220000 bytes
HTML transferred: 110000 bytes
Requests per second: 1637.70 [#/sec] (mean)
Time per request: 24.424 [ms] (mean)
Time per request: 0.611 [ms] (mean, across all concurrent requests)
Transfer rate: 1794.44 [Kbytes/sec] received

Connection times (ms)
              min mean[+/-sd] median max
Connect: 0 0 0.1 0 2
Processing: 2 24 15.1 20 187
Waiting: 2 24 15.1 20 187
Total: 2 24 15.1 20 187

Percentage of the requests served within a certain time (ms)
  50% 20
  66% 23
  75% 25
  80% 27
  90% 34
  95% 45
  98% 71
  99% 98
 100% 187 (longest request)
```

##### Benchmark Nginx+FPM driver

```bash
ab -n 10000 -c 40 -k http://127.0.0.1:80/ #command

This is ApacheBench, Version 2.3 <$Revision: 1903618 $>
Copyright 1996 Adam Twiss, Zeus Technology Ltd, http://www.zeustech.net/
Licensed to The Apache Software Foundation, http://www.apache.org/

Benchmarking 127.0.0.1 (be patient)
Completed 1000 requests
^C

Server Software:
Server Hostname: 127.0.0.1
Server Port: 8000

Document Path: /
Document length: 11 bytes

Concurrency Level: 40
Time taken for tests: 17.470 seconds
Complete requests: 1558
Failed requests: 0
Keep-Alive requests: 0
Total transferred: 1782352 bytes
HTML transferred: 17138 bytes
Requests per second: 89.18 [#/sec] (mean)
Time per request: 448.532 [ms] (mean)
Time per request: 11.213 [ms] (mean, across all concurrent requests)
Transfer rate: 99.63 [Kbytes/sec] received

Connection times (ms)
              min mean[+/-sd] median max
Connect: 0 0 0.2 0 2
Processing: 12 435 41.3 437 500
Waiting: 11 435 41.3 437 500
Total: 13 436 41.2 437 501

Percentage of the requests served within a certain time (ms)
  50% 437
  66% 441
  75% 444
  80% 445
  90% 457
  95% 467
  98% 476
  99% 487
 100% 501 (longest request)
```

> open `http://127.0.0.1:8008/`

#### How to use thinkphp

```bash
#Install
composer require cclilshy/p-ripple-drive

#run
php think p:run

# -l | --listen Service listening address, the default is http://127.0.0.1:8008
# -t | --threads Number of service threads, default is 4
```

> open `http://127.0.0.1:8008/`
---

### Static file access

> Traditional FPM projects cannot directly access files under the public path in CLI running mode.
> You can configure the proxy to the public path through Nginx routing or create your own route to solve this need
> The following are two reference solutions (Laravel)

##### Solution (Nginx proxy, recommended)

> Configure Nginx pseudo-static

```nginx
location/{
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

##### Solution (standalone operation)

> Add Laravel routing items

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

### More ways to use PRipple

For the latest documentation, please go to ["Usage Documentation"](https://github.com/cloudtay/p-ripple-core.git)
