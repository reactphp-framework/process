<?php

namespace Reactphp\Framework\Process\Tests;

use PHPUnit\Framework\TestCase;
use Reactphp\Framework\Process\ProcessManager;
use function React\Async\await;
use React\Promise\Deferred;
use React\EventLoop\Loop;

class MultipleCallbackTest extends TestCase
{
    public function testMultipleCallbackTest()
    {
        ProcessManager::instance()->initProcessNumber(2);

        $promises = [];
        ProcessManager::instance()->setNumber(2);
        ProcessManager::instance()->reload();
        for ($i = 0; $i < 10; $i++) {
            (function ($i) use (&$promises) {
                $deferred = new Deferred();
                $stream = ProcessManager::instance()->callback(function ($stream) use ($i) {
                    $timer = Loop::addPeriodicTimer(1, function () use ($stream, $i) {
                        $stream->write($i);
                    });
                    Loop::addTimer(5, function () use ($stream, $timer) {
                        Loop::cancelTimer($timer);
                        $stream->end();
                    });
                    return $stream;
                });
                $data = '';
                $stream->on('data', function ($buffer) use (&$data) {
                    $data .= $buffer;
                });
                $stream->on('close', function () use ($deferred, &$data) {
                    $deferred->resolve($data);
                });
                $promises[] = $deferred->promise();
            })($i);
        }

        $data = await(\React\Promise\all($promises));
        $this->assertEquals([
            '0000',
            '1111',
            '2222',
            '3333',
            '4444',
            '5555',
            '6666',
            '7777',
            '8888',
            '9999',
        ], $data);
        ProcessManager::instance()->terminate();
    }
}
