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

namespace Ripple\Driver\Yii2;

use yii\web\CookieCollection;
use yii\web\HeaderCollection;

class Request extends \yii\web\Request
{
    /*** @var \yii\web\HeaderCollection */
    protected HeaderCollection $headers;

    /*** @var \yii\web\CookieCollection */
    protected CookieCollection $cookies;

    /*** @var string|null */
    protected string|null $csrfToken = null;

    /*** @var string */
    protected string $method = 'GET';

    public function getMethod(): string
    {
        return $this->method;
    }

    /**
     * @param string $value
     *
     * @return void
     */
    public function setMethod(string $value): void
    {
        $this->method = $value;
    }

    /**
     * @param \yii\web\HeaderCollection $headerCollection
     *
     * @return void
     */
    public function setHeaders(HeaderCollection $headerCollection): void
    {
        $this->headers = $headerCollection;
    }

    /**
     * @param \yii\web\CookieCollection $cookieCollection
     *
     * @return void
     */
    public function setCookies(CookieCollection $cookieCollection): void
    {
        $this->cookies = $cookieCollection;
    }

    /**
     * @param string|null $csrfToken
     *
     * @return void
     */
    public function setCsrfToken(string|null $csrfToken): void
    {
        if ($csrfToken !== null) {
            $this->csrfToken = $csrfToken;
        }
    }
}
