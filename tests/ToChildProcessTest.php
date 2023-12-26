<?php

namespace Reactphp\Framework\Process\Tests;

use PHPUnit\Framework\TestCase;
use Reactphp\Framework\Process\ProcessManager;
use function React\Async\await;
use React\Promise\Deferred;

class ToChildProcessTest extends TestCase
{
    public function testSendToChildProcessMessage()
    {
        ProcessManager::instance()->initProcessNumber(1);
        $stream = ProcessManager::instance()->callback(function ($stream) {
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
        ProcessManager::instance()->terminate();
    }
}
