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
    /*** @var \Ripple\Driver\Yii2\Request */
    private Request $injectRequest;

    /**
     * @param \Ripple\Http\Server\Request $originalRequest
     *
     * @return void
     */
    public function rippleDispatch(\Ripple\Http\Server\Request $originalRequest): void
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
     * @param \Ripple\Http\Server\Request $request
     *
     * @return \Ripple\Driver\Yii2\Request
     */
    public function rippleYii2RequestBuild(\Ripple\Http\Server\Request $request): Request
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
