<?php

require __DIR__ . '/../vendor/autoload.php';

use Reactphp\Framework\Process\ProcessManager;

ProcessManager::instance()->initProcessNumber(1);


ProcessManager::instance()->log = true;
ProcessManager::instance()->debug = true;

$stream = ProcessManager::instance()->callback(function () {
    return [
        'hello' => 'world'
    ];
}, true);

$stream->on('data', function ($data) {
    var_dump($data);
});
