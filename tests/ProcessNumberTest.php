<?php

namespace Reactphp\Framework\Process\Tests;

use PHPUnit\Framework\TestCase;
use Reactphp\Framework\Process\ProcessManager;
use function React\Async\await;
use React\Promise\Deferred;
use React\EventLoop\Loop;

class ProcessNumberTest extends TestCase
{
    public function testProcessNumber()
    {
        ProcessManager::instance('process_number')->initProcessNumber(5);
        // ProcessManager::instance('process_number')->debug = true;
        // ProcessManager::instance('process_number')->log = true;
        ProcessManager::instance('process_number')->setCheckCycle(1);
        ProcessManager::instance('process_number')->setOverTime(2);
        ProcessManager::instance('process_number')->setMin(1);
        ProcessManager::instance('process_number')->setMax(10);
        ProcessManager::instance('process_number')->run();
        $deferred = new Deferred();
        Loop::addTimer(1, function () use ($deferred) {
            $deferred->resolve(ProcessManager::instance('process_number')->getProcessNumber());
        });
        $number = await($deferred->promise());
        $this->assertEquals(5, $number);

        $deferred = new Deferred();
        Loop::addTimer(3, function () use ($deferred) {
            $deferred->resolve(ProcessManager::instance('process_number')->getProcessNumber());
        });
        $number = await($deferred->promise());
        $this->assertEquals(1, $number);

        $deferred = new Deferred();
        $timer = Loop::addPeriodicTimer(0.1, function () {
            ProcessManager::instance('process_number')->callback(function () {
                return 'hello world';
            });
        });
        Loop::addTimer(2, function () use ($deferred, $timer) {
            Loop::cancelTimer($timer);
            $deferred->resolve(ProcessManager::instance('process_number')->getProcessNumber());
            ProcessManager::instance('process_number')->terminate();
        });

        $number = await($deferred->promise());
        $this->assertTrue($number > 1 && $number <= 10);
    }
}
