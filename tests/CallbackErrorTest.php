<?php

namespace Reactphp\Framework\Process\Tests;

use PHPUnit\Framework\TestCase;
use Reactphp\Framework\Process\ProcessManager;
use function React\Async\await;
use React\Promise\Deferred;

class CallbackErrorTest extends TestCase
{
    public function testCallbackError()
    {
        // ProcessManager::instance()->initProcessNumber();
        ProcessManager::instance()->debug = true;
        ProcessManager::instance()->log = true;
        $deferred = new Deferred();
        $stream = ProcessManager::instance('once')->callback(function ($stream) {
            $content = file_get_contents('./not-exists.txt');
            if ($content === false) {
                throw new \Exception('./not-exists.txt file not exists');
            }
        }, true);

        $stream->on('data', function ($data) use ($deferred) {
            $deferred->resolve($data);
        });

        $stream->on('error', function ($e) use ($deferred) {
            $deferred->reject($e);
        });
        try {
            $data = await($deferred->promise());
        } catch (\Throwable $th) {
            $data = json_decode($th->getMessage(), true)['message'];
        }
        $this->assertEquals('./not-exists.txt file not exists', $data);
    }
}
