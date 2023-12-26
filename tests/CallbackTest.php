<?php

namespace Reactphp\Framework\Process\Tests;

use PHPUnit\Framework\TestCase;
use Reactphp\Framework\Process\ProcessManager;
use function React\Async\await;
use React\Promise\Deferred;

class CallbackTest extends TestCase
{
    public function testOnceCallback()
    {
        ProcessManager::instance('once')->initProcessNumber();
        $deferred = new Deferred();
        $stream = ProcessManager::instance('once')->callback(function ($stream) {
            return 'hello world';
        }, true);

        $stream->on('data', function ($data) use ($deferred) {
            $deferred->resolve($data);
        });
        $data = await($deferred->promise());
        $this->assertEquals('hello world', $data);
    }

    public function testTerminateProcessCallback()
    {
        ProcessManager::instance()->initProcessNumber(1);
        $deferred = new Deferred();
        $stream = ProcessManager::instance()->callback(function ($stream) {
            return 'hello world';
        });
        $stream->on('data', function ($data) use ($deferred) {
            $deferred->resolve($data);
        });
        $data = await($deferred->promise());
        $this->assertEquals('hello world', $data);
        ProcessManager::instance()->terminate();
    }

    public function testCloseProcessCallback()
    {
        ProcessManager::instance()->initProcessNumber(1);

        $deferred = new Deferred();
        $stream = ProcessManager::instance()->callback(function ($stream) {
            return 'hello world';
        });

        $stream->on('data', function ($data) use ($deferred) {
            $deferred->resolve($data);
        });
        $data = await($deferred->promise());
        $this->assertEquals('hello world', $data);
        ProcessManager::instance()->close();
    }
}