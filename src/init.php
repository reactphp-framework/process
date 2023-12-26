<?php

if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require __DIR__ . '/../vendor/autoload.php';
} else {

    require __DIR__ . '/../../../autoload.php';
}

use Reactphp\Framework\Process\ProcessManager;
use MessagePack\BufferUnpacker;
use MessagePack\MessagePack;

ProcessManager::instance()->initProcessNumber(1);

$unpacker = new BufferUnpacker();

$stream = new \React\Stream\ReadableResourceStream(STDIN);
$pid = getmypid();
$stream->on('data', function ($chunk)  use ($pid, $unpacker) {
    $unpacker->append($chunk);
    if ($messages = $unpacker->tryUnpack()) {
        $unpacker->release();
        foreach ($messages as $message) {
            // log 
            // Process::replayLog("Process {$pid} receive data\n"); 
            ProcessManager::instance()->handleCallback($message);
        }
        return $messages;
    } else {
        ProcessManager::instance()->replay("Task {$pid} tryPack fail\n");
    }
});

$stream->on('end', function () {

});

// only test in one process
// $stream->emit('data', [MessagePack::pack([
//     'cmd' => 'task',
//     'uuid' => 'hello',
//     'data' => [
//         'serialized' => ProcessManager::instance()->getSeralized(function () {
//             return 'james';
//         })
//     ]
// ])]);

ProcessManager::instance()->replayInit();