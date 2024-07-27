<?php declare(strict_types=1);

namespace Psc\Drive\Stream;

use function serialize;
use function spl_object_hash;

class Command
{
    /**
     * @var string
     */
    public readonly string $id;

    /**
     * @var mixed
     */
    public mixed $result;

    /**
     * @param string $name
     * @param array  $arguments
     * @param array  $options
     */
    public function __construct(
        public readonly string $name,
        public readonly array  $arguments = [],
        public readonly array  $options = [],
    ) {
        $this->id = spl_object_hash($this);
    }

    /**
     * @param mixed $result
     * @return void
     */
    public function setResult(mixed $result): void
    {
        $this->result = $result;
    }

    /**
     * @return string
     */
    public function __toString(): string
    {
        return serialize($this);
    }
}
