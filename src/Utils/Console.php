<?php declare(strict_types=1);

namespace Psc\Drive\Utils;

use function str_pad;

trait Console
{
    /**
     * @param array  $row
     * @param string $type
     * @return string
     */
    private function formatRow(array $row, string $type = ''): string
    {
        $output    = '';
        $colorCode = $this->getColorCode($type);
        foreach ($row as $col) {
            $output .= str_pad("{$colorCode}{$col}\033[0m", 40);
        }
        return $output . "\n";
    }

    /**
     * @param string $item
     * @return string
     */
    private function formatList(string $item): string
    {
        return "  - $item\n";
    }

    /**
     * @param string $type
     * @return string
     */
    private function getColorCode(string $type): string
    {
        return match ($type) {
            'info' => "\033[1;36m",
            'thread' => "\033[1;33m",
            default => "",
        };
    }
}
