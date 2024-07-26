<?php
declare(strict_types=1);
/**
 * 测试fifo文件对缓冲区的处理方式
 */

$fileName = \md5(\uniqid() . \time());
$fileName = \sys_get_temp_dir() . "/$fileName";

\posix_mkfifo($fileName, 0666);

$stream = \fopen($fileName, 'w+');
\stream_set_blocking($stream, false);

echo "write to $fileName\n";

\sleep(3);

while (true) {
    $length = \fwrite($stream, \str_repeat('A', 1024 * 1024));
    \usleep(100);

    echo "write $length bytes\n";
}
