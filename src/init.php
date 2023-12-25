<?php

if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require __DIR__ . '/../vendor/autoload.php';
} else {

    require __DIR__ . '/../../../autoload.php';
}

use Reactphp\Framework\Process\Process;
use MessagePack\BufferUnpacker;
use MessagePack\MessagePack;

$unpacker = new BufferUnpacker();

$stream = new \React\Stream\ReadableResourceStream(STDIN);

$stream->on('data', function ($chunk)  use ($unpacker) {
    $pid = getmypid();
    $unpacker->append($chunk);
    if ($messages = $unpacker->tryUnpack()) {
        $unpacker->release();
        foreach ($messages as $message) {
            // log 
            // Process::replayLog("Process {$pid} receive data\n"); 
            Process::handleCallback($message);
        }
        
        return $messages;
    } else {
        Process::replay("Task {$pid} tryPack fail\n");
    }
});

$stream->on('end', function () {

});

// only test in one process
// $stream->emit('data', [MessagePack::pack([
//     'cmd' => 'task',
//     'uuid' => 'hello',
//     'data' => [
//         'serialized' => Process::getSeralized(function () {
//             return 'james';
//         })
//     ]
// ])]);

Process::replayInit();