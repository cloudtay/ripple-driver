<?php

namespace Psc\Drive\ThinkPHP;

use P\IO;
use P\Net;
use P\System;
use Psc\Store\Net\Http\Server\Request;
use Psc\Store\Net\Http\Server\Response;
use Psc\Store\System\Exception\ProcessException;
use think\App;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use think\response\File;
use function P\run;

class PDrive extends Command
{
    /**
     * @return void
     */
    protected function configure(): void
    {
        $this->setName('p-ripple')->setDescription('start the P-Drive service');
        $this->addOption('listen', 'l', Option::VALUE_OPTIONAL, 'listen', 'http://127.0.0.1:8008');
        $this->addOption('threads', 't', Option::VALUE_OPTIONAL, 'threads', 4);
    }

    /**
     * @param Input  $input
     * @param Output $output
     * @return void
     * @throws ProcessException
     */
    protected function execute(Input $input, Output $output): void
    {
        $context = stream_context_create([
            'socket' => [
                'so_reuseport' => 1,
                'so_reuseaddr' => 1,
            ],
        ]);

        $app               = new App();
        $http              = $app->http;
        $server            = Net::Http()->server($input->getOption('listen'), $context);
        $server->onRequest = function (Request $request, Response $response) use ($http) {
            $thinkRequest = new \think\Request();
            $thinkRequest->setUrl($request->getUri());
            $thinkRequest->setBaseUrl($request->getBaseUrl());
            $thinkRequest->setHost($request->getHost());
            $thinkRequest->setPathinfo($request->getPathInfo());
            $thinkRequest->setMethod($request->getMethod());
            $thinkRequest->withHeader($request->headers->all());
            $thinkRequest->withCookie($request->cookies->all());
            $thinkRequest->withFiles($request->files->all());
            $thinkRequest->withGet($request->query->all());
            $thinkRequest->withInput($request->getContent());

            $thinkResponse = $http->run($thinkRequest);
            if ($thinkResponse instanceof File) {
                $response->setContent(
                    IO::File()->open($thinkResponse->getData(), 'r')
                );
            } else {
                $response->setContent(
                    $thinkResponse->getData(),
                    $thinkResponse->getHeader('Content-Type')
                );
            }
            $response->respond();
        };

        $task = System::Process()->task(fn() => $server->listen());
        for ($i = 0; $i < $input->getOption('threads'); $i++) {
            $task->run();
        }
        run();
    }
}
