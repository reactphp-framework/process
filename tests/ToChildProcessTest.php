<?php

namespace Reactphp\Framework\Process\Tests;

use PHPUnit\Framework\TestCase;
use Reactphp\Framework\Process\Process;
use function React\Async\async;
use function React\Async\await;
use function React\Async\delay;
use React\Promise\Deferred;
use React\EventLoop\Loop;

class ToChildProcessTest extends TestCase
{
    public function testSendToChildProcessMessage()
    {
        Process::$log = false;
        $stream = Process::callback(function ($stream) {
            $stream->on('data', function ($buffer) use ($stream) {
                if ($buffer == 10) {
                    $stream->end($buffer + 1);
                } else {
                    $stream->write($buffer + 1);
                }
            });
            return $stream;
        });

        for ($i = 0; $i <= 10; $i++) {
            $stream->write($i);
        }

        $deferred = new Deferred();
        $data = '';
        $stream->on('data', function ($buffer) use (&$data) {
            $data .= $buffer;
        });

        $stream->on('close', function () use ($deferred, &$data) {
            $deferred->resolve($data);
        });
        $data = await($deferred->promise());
        $this->assertEquals('1234567891011', $data);
        Process::terminate();
    }
}
