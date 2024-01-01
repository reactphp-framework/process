<?php

require __DIR__ . '/../vendor/autoload.php';

use Reactphp\Framework\Process\ProcessManager;

ProcessManager::instance()->initProcessNumber(1);


ProcessManager::instance()->log = true;
ProcessManager::instance()->debug = true;

$stream = ProcessManager::instance()->callback(function () {
    error_log('hello world'); // debug to term not use echo
    return [
        'hello' => 'world'
    ];
}, true);

$stream->on('data', function ($data) {
    var_dump($data);
});
