<?php declare(strict_types=1);

namespace Psc\Drive\Utils;

use Exception;

use function chr;
use function ord;
use function pack;
use function strlen;
use function substr;
use function unpack;

/**
 * 0x7E帧格式编码和解码器
 */
class Frame
{
    private const FRAME_HEADER = 0x7E;
    private const FRAME_FOOTER = 0x7E;

    /**
     * 将数据编码为具有标头长度有效负载校验和和页脚的帧
     *
     * @param string $data
     * @return string
     */
    public function encodeFrame(string $data): string
    {
        $length   = strlen($data);
        $checksum = $this->calculateChecksum($data);

        $frame = chr(self::FRAME_HEADER);
        $frame .= pack('n', $length);
        $frame .= $data;
        $frame .= chr($checksum);
        $frame .= chr(self::FRAME_FOOTER);

        return $frame;
    }

    /**
     * 解码帧数据。
     *
     * @param string $frame
     * @return string
     * @throws Exception
     */
    public function decodeFrame(string $frame): string
    {
        if (ord($frame[0]) !== self::FRAME_HEADER || ord($frame[-1]) !== self::FRAME_FOOTER) {
            throw new Exception('Invalid frame: missing header or footer.');
        }

        $length   = unpack('n', substr($frame, 1, 2))[1];
        $data     = substr($frame, 3, $length);
        $checksum = ord($frame[3 + $length]);

        if ($checksum !== $this->calculateChecksum($data)) {
            throw new Exception('Invalid frame: checksum mismatch.');
        }

        return $data;
    }

    /**
     * 计算数据的简单校验和。
     *
     * @param string $data
     * @return int
     */
    private function calculateChecksum(string $data): int
    {
        $checksum = 0;
        for ($i = 0; $i < strlen($data); $i++) {
            $checksum ^= ord($data[$i]);
        }
        return $checksum;
    }
}
