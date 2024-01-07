<?php

namespace Reactphp\Framework\Process;

use React\ChildProcess\Process as BaseProcess;
use MessagePack\MessagePack;
use Laravel\SerializableClosure\SerializableClosure;
use MessagePack\BufferUnpacker;
use Evenement\EventEmitter;
use React\Stream;

class Process
{
    protected $number = 1;
    public  $log = false;
    public  $debug = false;

    protected  $stdout;

    protected  $processes = [];
    protected  $processInfo = [];
    protected  $unpackers = [];
    protected  $processInit = [];
    protected  $processEvents = [];

    protected  $processCallbackStreams = [];

    protected  $running = false;

    private $php;


    public function __construct($number, $php = null)
    {
        $this->number = $number;
        $this->php = $php;
    }

    public function setNumber($number)
    {
        $this->number = $number;
    }

    public function run()
    {
        if ($this->running) {
            return;
        }

        $this->running = true;
        $this->stdout = new \React\Stream\WritableResourceStream(STDOUT);

        $number = max(0, $this->number-count($this->processes));

        for ($i = 0; $i < $number; $i++) {
            $this->runProcess();
        }
    }

    protected function runProcess($once = false)
    {
        $process = new BaseProcess('exec ' . ($this->php ?: 'php') . ' ' . __DIR__ . '/init.php');
        $process->start();

        if (!$once) {
            $this->processes[$process->getPid()] = $process;
        }
        $this->unpackers[$process->getPid()] = new BufferUnpacker;
        $this->processEvents[$process->getPid()] = new EventEmitter;

        $process->stdout->on('data', function ($chunk) use ($process) {
            
            $pid = $process->getPid();
            $this->recordProcessActiveTime($pid);
            $unpacker = $this->unpackers[$pid];
            $unpacker->append($chunk);
            if ($messages = $unpacker->tryUnpack()) {
                $unpacker->release();
                foreach ($messages as $message) {
                    if (is_array($message)) {
                        if (isset($message['cmd'])) {
                            if ($this->log) {
                                $this->getStdout()->write(json_encode($message, JSON_UNESCAPED_UNICODE) . "-cmd\n");
                            }
                            if ($message['cmd'] == 'init') {
                                $this->processInit[$pid] = true;
                                $this->processEvents[$pid]->emit('init', [$message['data'] ?? []]);
                            } elseif (in_array($message['cmd'], [
                                'error',
                                'data',
                                'close',
                                'end',
                            ])) {
                                if (isset($this->processCallbackStreams[$pid][$message['uuid']])) {
                                    if ($message['cmd'] == 'close') {
                                        $stream = $this->processCallbackStreams[$pid][$message['uuid']]['stream'];
                                        unset($this->processCallbackStreams[$pid][$message['uuid']]);
                                        // friendly close
                                        $stream->end();
                                    } elseif ($message['cmd'] == 'end') {
                                        $read = $this->processCallbackStreams[$pid][$message['uuid']]['read'];
                                        unset($this->processCallbackStreams[$pid][$message['uuid']]);
                                        $read->end($message['data']);
                                    } elseif ($message['cmd'] == 'error') {
                                        $read = $this->processCallbackStreams[$pid][$message['uuid']]['read'];
                                        unset($this->processCallbackStreams[$pid][$message['uuid']]);
                                        $read->emit('error', [new \Exception($message['data'])]);
                                        // friendly close
                                        $read->end();
                                    } elseif ($message['cmd'] == 'data') {
                                        $this->processCallbackStreams[$pid][$message['uuid']]['read']->write($message['data']);
                                    }
                                }
                            } elseif ($message['cmd'] == 'log') {
                                //$this->getStdout()->write(json_encode($message, JSON_UNESCAPED_UNICODE)."\n");
                            }
                        }
                    }
                }

                return $messages;
            }
        });


        $process->stdout->on('end', function () use ($process) {
            $this->debug([
                'pid' => $process->getPid(),
                'end' => 'end',
            ]);
        });

        $process->stdout->on('error', function (\Exception $e) use ($process) {
            $this->debug([
                'pid' => $process->getPid(),
                'error' => $e->getMessage(),
            ]);
        });

        $process->stdout->on('close', function () use ($process) {
            $this->debug([
                'pid' => $process->getPid(),
                'close' => 'close',
            ]);
        });

        $process->stderr->on('data', function ($chunk) use ($process) {
            $this->debug([
                'pid' => $process->getPid(),
                'stderr' => $chunk,
            ]);
        });

        $process->on('exit', function ($exitCode, $termSignal) use ($process, $once) {
            $this->debug([
                'pid' => $process->getPid(),
                'exitCode' => $exitCode,
                'termSignal' => $termSignal,
            ]);
            unset($this->processes[$process->getPid()]);
            unset($this->unpackers[$process->getPid()]);
            foreach (($this->processCallbackStreams[$process->getPid()] ?? []) as $uuid => $stream) {
                $stream['stream']->close();
            }
            unset($this->processInit[$process->getPid()]);
            unset($this->processEvents[$process->getPid()]);
            if (!$once) {
                // 进程主动关闭的情况下不启动新进程
                if (!$this->isTerminateOrClose($process->getPid())) {
                    $this->runProcess();
                }
            }
            unset($this->processInfo[$process->getPid()]);
        });
        return $process;
    }

    public function debug($msg)
    {
        if (!$this->debug) {
            return;
        }

        if (is_array($msg)) {
            $msg = json_encode($msg, JSON_UNESCAPED_UNICODE);
        }
        echo $msg . "\n";
    }

    public function callback($closure, $once = false)
    {
        if (!$this->running) {
            if (!$once) {
                $this->run();
            }
        } else {
            if (!$once) {
                // 之前关闭过进程, 重新启动
                if (count($this->processes) == 0) {
                    $this->runProcess();
                }
            }
        }

        $serialized = $this->getSeralized($closure);
        // 随机一个进程
        if ($once) {
            $process = $this->runProcess(true);
        } else {
            $process = $this->randomProcess();
        }
        $uuid = $process->getPid() . '-' . time() . '-' . uniqid() . '-' . md5($serialized);

        $read = new Stream\ThroughStream;
        $write = new Stream\ThroughStream;

        $stream = new Stream\CompositeStream($read, $write);
        $this->processCallbackStreams[$process->getPid()][$uuid] = [
            'read' => $read,
            'write' => $write,
            'stream' => $stream,
        ];

        $pack = MessagePack::pack([
            'cmd' => 'callback',
            'uuid' => $uuid,
            'data' => [
                'serialized' => $serialized
            ]
        ]);


        $packs = [];
        if (!isset($this->processInit[$process->getPid()])) {
            $this->processEvents[$process->getPid()]->once('init', function () use ($process, $pack, &$packs) {
                $process->stdin->write($pack);
                foreach ($packs as $_pack) {
                    $process->stdin->write($_pack);
                }
                $packs = null;
            });
        } else {
            $process->stdin->write($pack);
        }

        $write->on('data', function ($data) use ($process, $uuid, &$packs) {
            $this->recordProcessActiveTime($process->getPid());

            $pack = MessagePack::pack([
                'cmd' => 'data',
                'uuid' => $uuid,
                'data' => $data
            ]);
            // 避免没有初始化，先存起来
            if (!isset($this->processInit[$process->getPid()])) {
                $packs[] = $pack;
            } else {
                $process->stdin->write($pack);
            }           
        });

        $stream->on('error', function ($e) use ($stream, $process, $uuid) {
            // 主动error
            if (isset($this->processCallbackStreams[$process->getPid()][$uuid])) {
                unset($this->processCallbackStreams[$process->getPid()][$uuid]);
                $process->stdin->write(MessagePack::pack([
                    'cmd' => 'error',
                    'uuid' => $uuid,
                    'data' => json_encode([
                        'message' => $e->getMessage(),
                        'code' => $e->getCode(),
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                    ], JSON_UNESCAPED_UNICODE),
                ]));
                $stream->close();
            }
        });

        $stream->on('end', function () use ($process, $uuid) {
            // 主动end
            if (isset($this->processCallbackStreams[$process->getPid()][$uuid])) {
                unset($this->processCallbackStreams[$process->getPid()][$uuid]);
                $process->stdin->write(MessagePack::pack([
                    'cmd' => 'end',
                    'uuid' => $uuid,
                ]));
            }
        });

        $stream->on('close', function () use ($process, $once, $uuid) {

            // 主动close
            if (isset($this->processCallbackStreams[$process->getPid()][$uuid])) {
                unset($this->processCallbackStreams[$process->getPid()][$uuid]);
                $process->stdin->write(MessagePack::pack([
                    'cmd' => 'close',
                    'uuid' => $uuid,
                ]));
            }

            if ($once) {
                $process->terminate();
            }
        });
        $this->recordProcessActiveTime($process->getPid());

        return $stream;
    }

    private function recordProcessActiveTime($pid)
    {
        $this->processInfo[$pid]['active_time'] = time();
    }



    public function terminate($pid = null)
    {
        $processes = $this->processes;
        if ($pid) {
            $process = $this->processes[$pid] ?? [];
            if ($process) {
                $processes = [
                    $pid => $process
                ];
            }
        }

        foreach ($processes as $process) {
            $this->processInfo[$process->getPid()]['terminate'] = true;
            // 移除掉，避免找到这个进程
            unset($this->processes[$process->getPid()]);
            $process->terminate();
        }
    }

    public function close($pid = null)
    {
        $processes = $this->processes;
        if ($pid) {
            $process = $this->processes[$pid] ?? [];
            if ($process) {
                $processes = [
                    $pid => $process
                ];
            }
        }

        foreach ($processes as $process) {
            $this->processInfo[$process->getPid()]['close'] = true;
            // 移除掉，避免找到这个进程
            unset($this->processes[$process->getPid()]);
            $process->close();
        }
    }
    public function reload()
    {
        $this->terminate();
        $this->running = false;
        $this->run();
    }
    
    public function restart()
    {
        $this->close();
        $this->running = false;
        $this->run();
    }



    private function isTerminateOrClose($pid)
    {
        return ($this->processInfo[$pid]['terminate'] ?? false) || ($this->processInfo[$pid]['close'] ?? false);
    }

    private function randomProcess()
    {
        if (count($this->processes) == 0) {
            return $this->runProcess();
        }
        return $this->processes[array_rand($this->processes)];
    }

    public function getSeralized($closure)
    {
        return serialize(new SerializableClosure($closure));
    }

    public function handleCallback($message)
    {

        $cmd = $message['cmd'];
        $uuid = $message['uuid'];
        $pid = getmypid();
        if ($cmd == 'callback') {
            $serialized = $message['data']['serialized'];

            $closure = unserialize($serialized)->getClosure();

            $read = new Stream\ThroughStream;
            $write = new Stream\ThroughStream;

            $stream = new Stream\CompositeStream($read, $write);
            $this->processCallbackStreams[$pid][$uuid] = [
                'read' => $read,
                'write' => $write,
                'stream' => $stream,
            ];

            $write->on('data', function ($data) use ($uuid) {
                $this->replayData($uuid, $data);
            });

            $stream->on('end', function () use ($pid, $uuid) {

                // 主动end
                if (isset($this->processCallbackStreams[$pid][$uuid])) {
                    unset($this->processCallbackStreams[$pid][$uuid]);
                    $this->replayEnd($uuid);
                }
            });

            $stream->on('error', function ($e) use ($stream, $pid, $uuid) {
                // 主动error
                if (isset($this->processCallbackStreams[$pid][$uuid])) {
                    unset($this->processCallbackStreams[$pid][$uuid]);
                    $this->replayError($uuid, json_encode([
                        'message' => $e->getMessage(),
                        'code' => $e->getCode(),
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                    ], JSON_UNESCAPED_UNICODE));
                    $stream->close();
                }
            });

            $stream->on('close', function () use ($pid, $uuid) {
                // 主动close
                if (isset($this->processCallbackStreams[$pid][$uuid])) {
                    unset($this->processCallbackStreams[$pid][$uuid]);
                    $this->replayClose($uuid);
                }
            });

            try {
                $r = $closure($stream);
                if ($r !== $stream) {
                    $stream->end($r);
                }
            } catch (\Throwable $th) {
                $stream->emit('error', [$th]);
            }

        } elseif (in_array($cmd, [
            'data',
            'end',
            'error',
            'close',
        ])) {


            if (isset($this->processCallbackStreams[$pid][$uuid])) {
                $this->replayLog('cmd:' . $cmd . ' uuid:' . $uuid . ' pid:' . $pid);
                if ($cmd == 'close') {
                    $stream = $this->processCallbackStreams[$pid][$uuid]['stream'];
                    unset($this->processCallbackStreams[$pid][$uuid]);
                    // friendly close
                    $stream->end();
                } elseif ($cmd == 'error') {
                    $read = $this->processCallbackStreams[$pid][$uuid]['read'];
                    unset($this->processCallbackStreams[$pid][$uuid]);
                    $read->emit('error', [new \Exception($message['data'])]);
                    // friendly close
                    $read->end();
                } elseif ($cmd == 'end') {
                    $read = $this->processCallbackStreams[$pid][$uuid]['read'];
                    unset($this->processCallbackStreams[$pid][$uuid]);
                    $read->end($message['data']);
                } elseif ($cmd == 'data') {
                    $this->processCallbackStreams[$pid][$uuid]['read']->write($message['data']);
                }
            }
        }
        // $this->replayLog('cmd:' . $cmd . ' uuid:' . $uuid . ' pid:' . $pid.' '. microtime(true));

    }



    // 给父进程回复

    private function replayData($uuid, $data)
    {
        $this->replay([
            'cmd' => 'data',
            'uuid' => $uuid,
            'data' => $data
        ]);
    }


    private function replayClose($uuid, $data = null)
    {
        $this->replay([
            'cmd' => 'close',
            'uuid' => $uuid,
            'data' => $data
        ]);
    }

    private function replayError($uuid, $data = null)
    {
        $this->replay([
            'cmd' => 'error',
            'uuid' => $uuid,
            'data' => $data
        ]);
    }

    private function replayEnd($uuid, $data = null)
    {
        $this->replay([
            'cmd' => 'end',
            'uuid' => $uuid,
            'data' => $data
        ]);
    }

    public function replayInit($extra = [])
    {
        $pid = getmypid();
        $this->replay([
            'cmd' => 'init',
            'data' => [
                'msg' => "Process {$pid} init success!\n",
                'extra' => $extra
            ]
        ]);
    }
    public function replayLog($data)
    {

        $this->replay([
            'cmd' => 'log',
            'data' => $data
        ]);
    }

    public function replay($data)
    {
        $this->getStdout()->write(MessagePack::pack($data));
    }


    public function getStdout()
    {
        if ($this->stdout) {
            return $this->stdout;
        }
        return $this->stdout = new \React\Stream\WritableResourceStream(STDOUT);
    }
}
