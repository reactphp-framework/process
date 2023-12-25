<?php

namespace Reactphp\Framework\Process\Tests;

use PHPUnit\Framework\TestCase;
use Reactphp\Framework\Process\Process;
use function React\Async\await;
use React\Promise\Deferred;

class CallbackTest extends TestCase
{
    public function testOnceCallback()
    {
        Process::$log = false;

        $deferred = new Deferred();
        $stream = Process::callback(function ($stream) {
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
        $deferred = new Deferred();
        $stream = Process::callback(function ($stream) {
            return 'hello world';
        });
        $stream->on('data', function ($data) use ($deferred) {
            $deferred->resolve($data);
        });
        $data = await($deferred->promise());
        $this->assertEquals('hello world', $data);
        Process::terminate();
    }

    public function testCloseProcessCallback()
    {
        $deferred = new Deferred();
        $stream = Process::callback(function ($stream) {
            return 'hello world';
        });

        $stream->on('data', function ($data) use ($deferred) {
            $deferred->resolve($data);
        });
        $data = await($deferred->promise());
        $this->assertEquals('hello world', $data);
        Process::close();
    }
}