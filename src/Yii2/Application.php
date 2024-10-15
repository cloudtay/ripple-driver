<?php declare(strict_types=1);
/*
 * Copyright (c) 2023-2024.
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 *
 * 特此免费授予任何获得本软件及相关文档文件（“软件”）副本的人，不受限制地处理
 * 本软件，包括但不限于使用、复制、修改、合并、出版、发行、再许可和/或销售
 * 软件副本的权利，并允许向其提供本软件的人做出上述行为，但须符合以下条件：
 *
 * 上述版权声明和本许可声明应包含在本软件的所有副本或主要部分中。
 *
 * 本软件按“原样”提供，不提供任何形式的保证，无论是明示或暗示的，
 * 包括但不限于适销性、特定目的的适用性和非侵权性的保证。在任何情况下，
 * 无论是合同诉讼、侵权行为还是其他方面，作者或版权持有人均不对
 * 由于软件或软件的使用或其他交易而引起的任何索赔、损害或其他责任承担责任。
 */

namespace Psc\Drive\Yii2;

use Throwable;
use yii\base\ExitException;
use yii\base\InvalidCallException;
use yii\web\Cookie;
use yii\web\CookieCollection;
use yii\web\HeaderCollection;

use function str_replace;
use function str_starts_with;
use function strtolower;
use function strval;
use function substr;
use function ucwords;

class Application extends \yii\web\Application
{
    /*** @var \Psc\Drive\Yii2\Request */
    private Request $injectRequest;

    /**
     * @param \Psc\Core\Http\Server\Request $originalRequest
     *
     * @return void
     */
    public function rippleDispatch(\Psc\Core\Http\Server\Request $originalRequest): void
    {
        $this->injectRequest = $this->rippleYii2RequestBuild($originalRequest);
        $originalResponse    = $originalRequest->getResponse();

        try {
            $this->state = parent::STATE_BEFORE_REQUEST;
            $this->trigger(parent::EVENT_BEFORE_REQUEST);

            $this->state = parent::STATE_HANDLING_REQUEST;
            $response    = $this->handleRequest($this->getRequest());

            $this->state = parent::STATE_AFTER_REQUEST;
            $this->trigger(parent::EVENT_AFTER_REQUEST);

            $this->state = parent::STATE_SENDING_RESPONSE;
            $originalResponse->setStatusCode($response->statusCode);
            $originalResponse->setBody($response->data);
            $originalResponse->withHeaders($response->headers->toArray());

            /*** @var \yii\web\Cookie $cookie */
            foreach ($response->cookies as $cookie) {
                $originalResponse->withHeader('Set-Cookie', $cookie->__toString());
            }

            $originalResponse->setStatusText($response->statusText);
            $originalResponse->respond();
            $this->state = parent::STATE_END;

            return;
        } catch (Throwable $e) {
            try {
                $this->end($e->getCode(), $response ?? null);
            } catch (ExitException $ej) {
                return;
            }
            return;
        }
    }

    /**
     * @param \Psc\Core\Http\Server\Request $request
     *
     * @return \Psc\Drive\Yii2\Request
     */
    public function rippleYii2RequestBuild(\Psc\Core\Http\Server\Request $request): Request
    {
        $headers = new HeaderCollection();
        $cookies = new CookieCollection();
        foreach ($request->SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $headers->add(
                    str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5))))),
                    $value
                );
            }
        }

        foreach ($request->COOKIE as $key => $value) {
            try {
                $cookies->add(new Cookie([
                    'name'  => $key,
                    'value' => $value,
                ]));
            } catch (InvalidCallException) {
                // ignore
            }
        }

        $yii2Request = new Request();
        $yii2Request->setHeaders($headers);
        $yii2Request->setCookies($cookies);
        $yii2Request->setMethod($request->SERVER['REQUEST_METHOD']);
        $yii2Request->setUrl($request->SERVER['REQUEST_URI']);
        $yii2Request->setBodyParams($request->POST);
        $yii2Request->setQueryParams($request->GET);
        $yii2Request->setCsrfToken($request->COOKIE['csrf_token'] ?? null);
        $yii2Request->rawBody = strval($request->CONTENT);
        return $yii2Request;
    }

    /**
     * @return \yii\web\Request
     */
    public function getRequest(): \yii\web\Request
    {
        return $this->injectRequest ?? parent::getRequest();
    }
}
