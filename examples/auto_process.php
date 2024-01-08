<?php

require __DIR__ . '/../vendor/autoload.php';

use Reactphp\Framework\Process\ProcessManager;
use React\EventLoop\Loop;

ProcessManager::instance()->initProcessNumber(5);
// ProcessManager::instance()->log = true;
// ProcessManager::instance()->debug = true;
ProcessManager::instance()->setCheckCycle(2);
ProcessManager::instance()->setOverTime(10);
ProcessManager::instance()->setMin(1);
ProcessManager::instance()->setMax(10);
ProcessManager::instance()->run();

Loop::addPeriodicTimer(1, function () {
    echo "current process number: " . ProcessManager::instance()->getProcessNumber() . "\n";
});

Loop::addPeriodicTimer(1, function () {
    ProcessManager::instance()->callback(function () {
        return 'hello world';
    });
});

