<?php

require __DIR__ . '/../vendor/autoload.php';

use Reactphp\Framework\Process\Process;

$stream = Process::callback(function () {
    return [
        'hello' => 'world'
    ];
}, true);

$stream->on('data', function ($data) {
    var_dump($data);
});