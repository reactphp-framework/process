<?php

require __DIR__ . '/../vendor/autoload.php';

use Reactphp\Framework\Process\ProcessManager;
use React\EventLoop\Loop;

$process = ProcessManager::instance()->initProcessNumber(20);
ProcessManager::instance()->log = true;
ProcessManager::instance()->debug = true;

function test($j)
{
    echo "test {$j}\n";
    $stream = ProcessManager::instance()->callback(function ($stream) use ($j) {
        $i = 0;
        $timer = Loop::addPeriodicTimer(1, function () use ($stream, $j, &$i) {
            $stream->write('hello world-' . $j . '-' . $i++);
        });
        Loop::addTimer(10, function () use ($stream, $timer, $j) {
            Loop::cancelTimer($timer);
            $stream->end($j.'-end');
        });

        return $stream;
    });

    $stream->on('data', function ($data) {
        echo ($data) . "\n";
    });

    $stream->on('end', function ($data) {
        echo "end\n";
    });

    $stream->on('close', function () {
        echo "closed\n";
    });
}

for ($i = 0; $i < 30; $i++) {
    test($i);
}
