<?php declare(strict_types=1);
/**
 * Copyright © 2024 cclilshy
 * Email: jingnigg@gmail.com
 *
 * This software is licensed under the MIT License.
 * For full license details, please visit: https://opensource.org/licenses/MIT
 *
 * By using this software, you agree to the terms of the license.
 * Contributions, suggestions, and feedback are always welcome!
 */

namespace Ripple\Driver\Laravel\Response;

use Closure;
use Iterator;
use Symfony\Component\HttpFoundation\Response;

class IteratorResponse extends Response
{
    /*** @var \Iterator */
    private Iterator $asyncContent;

    /**
     * @param Iterator|Closure $generator
     * @param array            $headers
     */
    public function __construct(Iterator|Closure $generator, array $headers = [])
    {
        parent::__construct(null, 200, $headers);
        if ($generator instanceof Closure) {
            $generator = $generator();
        }
        $this->asyncContent = $generator;
    }

    /**
     * @return mixed
     */
    public function getIterator(): Iterator
    {
        return $this->asyncContent;
    }
}
