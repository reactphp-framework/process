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
    public static $number = 1;
    public static $log = false;
    public static $debug = false;

    protected static $stdout;

    protected static $processes = [];
    protected static $processInfo = [];
    protected static $unpackers = [];
    protected static $processInit = [];
    protected static $processEvents = [];

    protected static $processCallbackStreams = [];

    protected static $running = false;

    public static function run($php = null)
    {
        if (static::$running) {
            return;
        }

        static::$running = true;
        static::$stdout = new \React\Stream\WritableResourceStream(STDOUT);

        $number = max(0, static::$number-count(static::$processes));

        for ($i = 0; $i < $number; $i++) {
            static::runProcess($php);
        }
    }

    protected static function runProcess($php = null, $once = false)
    {
        $process = new BaseProcess('exec ' . ($php ?: 'php') . ' ' . __DIR__ . '/init.php');
        $process->start();

        if (!$once) {
            static::$processes[$process->getPid()] = $process;
        }
        static::$unpackers[$process->getPid()] = new BufferUnpacker;
        static::$processEvents[$process->getPid()] = new EventEmitter;

        $process->stdout->on('data', function ($chunk) use ($process) {
            
            $pid = $process->getPid();
            static::recorProcessActiveTime($pid);
            $unpacker = static::$unpackers[$pid];
            $unpacker->append($chunk);
            if ($messages = $unpacker->tryUnpack()) {
                $unpacker->release();
                foreach ($messages as $message) {
                    if (is_array($message)) {
                        if (isset($message['cmd'])) {
                            if (static::$log) {
                                static::getStdout()->write(json_encode($message, JSON_UNESCAPED_UNICODE) . "-cmd\n");
                            }
                            if ($message['cmd'] == 'init') {
                                static::$processInit[$pid] = true;
                                static::$processEvents[$pid]->emit('init', [$message['data'] ?? []]);
                            } elseif (in_array($message['cmd'], [
                                'error',
                                'data',
                                'close',
                                'end',
                            ])) {
                                if (isset(static::$processCallbackStreams[$pid][$message['uuid']])) {
                                    if ($message['cmd'] == 'close') {
                                        $stream = static::$processCallbackStreams[$pid][$message['uuid']]['stream'];
                                        unset(static::$processCallbackStreams[$pid][$message['uuid']]);
                                        $stream->close();
                                    } elseif ($message['cmd'] == 'end') {
                                        $read = static::$processCallbackStreams[$pid][$message['uuid']]['read'];
                                        unset(static::$processCallbackStreams[$pid][$message['uuid']]);
                                        $read->end($message['data']);
                                    } elseif ($message['cmd'] == 'error') {
                                        $read = static::$processCallbackStreams[$pid][$message['uuid']]['read'];
                                        unset(static::$processCallbackStreams[$pid][$message['uuid']]);
                                        $read->emit('error', [new \Exception($message['data'])]);
                                    } elseif ($message['cmd'] == 'data') {
                                        static::$processCallbackStreams[$pid][$message['uuid']]['read']->write($message['data']);
                                    }
                                }
                            } elseif ($message['cmd'] == 'log') {
                                //static::getStdout()->write(json_encode($message, JSON_UNESCAPED_UNICODE)."\n");
                            }
                        }
                    }
                }

                return $messages;
            }
        });


        $process->stdout->on('end', function () use ($process) {
            static::debug([
                'pid' => $process->getPid(),
                'end' => 'end',
            ]);
        });

        $process->stdout->on('error', function (\Exception $e) use ($process) {
            static::debug([
                'pid' => $process->getPid(),
                'error' => $e->getMessage(),
            ]);
        });

        $process->stdout->on('close', function () use ($process) {
            static::debug([
                'pid' => $process->getPid(),
                'close' => 'close',
            ]);
        });

        $process->on('exit', function ($exitCode, $termSignal) use ($process, $php, $once) {
            static::debug([
                'pid' => $process->getPid(),
                'exitCode' => $exitCode,
                'termSignal' => $termSignal,
            ]);
            unset(static::$processes[$process->getPid()]);
            unset(static::$unpackers[$process->getPid()]);
            foreach ((static::$processCallbackStreams[$process->getPid()] ?? []) as $uuid => $stream) {
                $stream['stream']->close();
            }
            unset(static::$processInit[$process->getPid()]);
            unset(static::$processEvents[$process->getPid()]);
            if (!$once) {
                // 进程主动关闭的情况下不启动新进程
                if (!static::isTerminateOrClose($process->getPid())) {
                    static::runProcess($php);
                }
            }
            unset(static::$processInfo[$process->getPid()]);
        });
        return $process;
    }

    public static function debug($msg)
    {
        if (!static::$debug) {
            return;
        }

        if (is_array($msg)) {
            $msg = json_encode($msg, JSON_UNESCAPED_UNICODE);
        }
        echo $msg . "\n";
    }

    public static function callback($closure, $once = false, $php = null)
    {
        if (!static::$running) {
            if (!$once) {
                static::run($php);
            }
        } else {
            if (!$once) {
                // 之前关闭过进程, 重新启动
                if (count(static::$processes) == 0) {
                    static::runProcess($php);
                }
            }
        }

        $serialized = static::getSeralized($closure);
        // 随机一个进程
        if ($once) {
            $process = static::runProcess($php, true);
        } else {
            $process = static::randomProcess($php = null);
        }
        $uuid = $process->getPid() . '-' . time() . '-' . uniqid() . '-' . md5($serialized);

        $read = new Stream\ThroughStream;
        $write = new Stream\ThroughStream;

        $stream = new Stream\CompositeStream($read, $write);
        static::$processCallbackStreams[$process->getPid()][$uuid] = [
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
        if (!isset(static::$processInit[$process->getPid()])) {
            static::$processEvents[$process->getPid()]->once('init', function () use ($process, $pack, &$packs) {
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
            static::recorProcessActiveTime($process->getPid());

            $pack = MessagePack::pack([
                'cmd' => 'data',
                'uuid' => $uuid,
                'data' => $data
            ]);
            // 避免没有初始化，先存起来
            if (!isset(static::$processInit[$process->getPid()])) {
                $packs[] = $pack;
            } else {
                $process->stdin->write($pack);
            }           
        });

        $stream->on('error', function ($e) use ($process, $uuid) {
            // 主动error
            if (isset(static::$processCallbackStreams[$process->getPid()][$uuid])) {
                unset(static::$processCallbackStreams[$process->getPid()][$uuid]);
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
            }
        });

        $stream->on('end', function () use ($process, $uuid) {
            // 主动end
            if (isset(static::$processCallbackStreams[$process->getPid()][$uuid])) {
                unset(static::$processCallbackStreams[$process->getPid()][$uuid]);
                $process->stdin->write(MessagePack::pack([
                    'cmd' => 'end',
                    'uuid' => $uuid,
                ]));
            }
        });

        $stream->on('close', function () use ($process, $once, $uuid) {

            // 主动close
            if (isset(static::$processCallbackStreams[$process->getPid()][$uuid])) {
                unset(static::$processCallbackStreams[$process->getPid()][$uuid]);
                $process->stdin->write(MessagePack::pack([
                    'cmd' => 'close',
                    'uuid' => $uuid,
                ]));
            }

            if ($once) {
                $process->terminate();
            }
        });
        static::recorProcessActiveTime($process->getPid());

        return $stream;
    }

    private static function recorProcessActiveTime($pid)
    {
        static::$processInfo[$pid]['active_time'] = time();
    }



    public static function terminate($pid = null)
    {
        $processes = static::$processes;
        if ($pid) {
            $process = static::$processes[$pid] ?? [];
            if ($process) {
                $processes = [
                    $pid => $process
                ];
            }
        }

        foreach ($processes as $process) {
            static::$processInfo[$process->getPid()]['terminate'] = true;
            // 移除掉，避免找到这个进程
            unset(static::$processes[$process->getPid()]);
            $process->terminate();
        }
    }

    public static function close($pid = null)
    {
        $processes = static::$processes;
        if ($pid) {
            $process = static::$processes[$pid] ?? [];
            if ($process) {
                $processes = [
                    $pid => $process
                ];
            }
        }

        foreach ($processes as $process) {
            static::$processInfo[$process->getPid()]['close'] = true;
            // 移除掉，避免找到这个进程
            unset(static::$processes[$process->getPid()]);
            $process->close();
        }
    }
    public static function reload($php = null)
    {
        static::terminate();
        static::$running = false;
        static::run($php);
    }
    
    public static function restart($php = null)
    {
        static::close();
        static::$running = false;
        static::run($php);
    }



    private static function isTerminateOrClose($pid)
    {
        return (static::$processInfo[$pid]['terminate'] ?? false) || (static::$processInfo[$pid]['close'] ?? false);
    }

    private static function randomProcess($php = null)
    {
        if (count(static::$processes) == 0) {
            return static::runProcess($php);
        }
        return static::$processes[array_rand(static::$processes)];
    }

    public static function getSeralized($closure)
    {
        return serialize(new SerializableClosure($closure));
    }

    public static function handleCallback($message)
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
            static::$processCallbackStreams[$pid][$uuid] = [
                'read' => $read,
                'write' => $write,
                'stream' => $stream,
            ];

            $write->on('data', function ($data) use ($uuid) {
                static::replayData($uuid, $data);
            });

            $stream->on('end', function () use ($pid, $uuid) {

                // 主动end
                if (isset(static::$processCallbackStreams[$pid][$uuid])) {
                    unset(static::$processCallbackStreams[$pid][$uuid]);
                    static::replayEnd($uuid);
                }
            });

            $stream->on('error', function ($e) use ($pid, $uuid) {
                // 主动error
                if (isset(static::$processCallbackStreams[$pid][$uuid])) {
                    unset(static::$processCallbackStreams[$pid][$uuid]);
                    static::replayError($uuid, json_encode([
                        'message' => $e->getMessage(),
                        'code' => $e->getCode(),
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                    ], JSON_UNESCAPED_UNICODE));
                }
            });

            $stream->on('close', function () use ($pid, $uuid) {
                // 主动close
                if (isset(static::$processCallbackStreams[$pid][$uuid])) {
                    unset(static::$processCallbackStreams[$pid][$uuid]);
                    static::replayClose($uuid);
                }
            });

            try {
                $r = $closure($stream);
            } catch (\Throwable $th) {
                static::replayLog('error:' . json_encode([
                    'message' => $th->getMessage(),
                    'code' => $th->getCode(),
                    'file' => $th->getFile(),
                    'line' => $th->getLine(),
                ], JSON_UNESCAPED_UNICODE));
            }

            if ($r !== $stream) {
                $stream->end($r);
            }
        } elseif (in_array($cmd, [
            'data',
            'end',
            'error',
            'close',
        ])) {


            if (isset(static::$processCallbackStreams[$pid][$uuid])) {
                static::replayLog('cmd:' . $cmd . ' uuid:' . $uuid . ' pid:' . $pid);
                if ($cmd == 'close') {
                    $stream = static::$processCallbackStreams[$pid][$uuid]['stream'];
                    unset(static::$processCallbackStreams[$pid][$uuid]);
                    $stream->close();
                } elseif ($cmd == 'error') {
                    $read = static::$processCallbackStreams[$pid][$uuid]['read'];
                    unset(static::$processCallbackStreams[$pid][$uuid]);
                    $read->emit('error', [new \Exception($message['data'])]);
                } elseif ($cmd == 'end') {
                    $read = static::$processCallbackStreams[$pid][$uuid]['read'];
                    unset(static::$processCallbackStreams[$pid][$uuid]);
                    $read->end($message['data']);
                } elseif ($cmd == 'data') {
                    static::$processCallbackStreams[$pid][$uuid]['read']->write($message['data']);
                }
            }
        }
        // static::replayLog('cmd:' . $cmd . ' uuid:' . $uuid . ' pid:' . $pid.' '. microtime(true));

    }



    // 给父进程回复

    private static function replayData($uuid, $data)
    {
        static::replay([
            'cmd' => 'data',
            'uuid' => $uuid,
            'data' => $data
        ]);
    }


    private static function replayClose($uuid, $data = null)
    {
        static::replay([
            'cmd' => 'close',
            'uuid' => $uuid,
            'data' => $data
        ]);
    }

    private static function replayError($uuid, $data = null)
    {
        static::replay([
            'cmd' => 'error',
            'uuid' => $uuid,
            'data' => $data
        ]);
    }

    private static function replayEnd($uuid, $data = null)
    {
        static::replay([
            'cmd' => 'end',
            'uuid' => $uuid,
            'data' => $data
        ]);
    }

    public static function replayInit($extra = [])
    {
        $pid = getmypid();
        static::replay([
            'cmd' => 'init',
            'data' => [
                'msg' => "Process {$pid} init success!\n",
                'extra' => $extra
            ]
        ]);
    }
    public static function replayLog($data)
    {

        static::replay([
            'cmd' => 'log',
            'data' => $data
        ]);
    }

    public static function replay($data)
    {
        static::getStdout()->write(MessagePack::pack($data));
    }


    public static function getStdout()
    {
        if (static::$stdout) {
            return static::$stdout;
        }
        return static::$stdout = new \React\Stream\WritableResourceStream(STDOUT);
    }
}
