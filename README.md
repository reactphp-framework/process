# reactphp-framework-process

run php callback in reactphp [child-processes](https://github.com/reactphp/child-process)

## install

```
composer require reactphp-framework/process -vvv
```

## usage

### base
```php

<?php
require __DIR__ . '/vendor/autoload.php';

use Reactphp\Framework\Process\ProcessManager;
ProcessManager::instance()->initProcessNumber(1);

$stream = ProcessManager::instance()->callback(function () {
    return 'hello world';
});

$stream->on('data', function ($data) {
    var_dump($data);
    ProcessManager::instance()->terminate();
});
```

### stream

```php
require __DIR__ . '/vendor/autoload.php';

use Reactphp\Framework\Process\Process;
use React\EventLoop\Loop;
ProcessManager::instance()->initProcessNumber(1);

$stream = ProcessManager::instance()->callback(function ($stream) {
    $stream->end('hello world');
    return $stream;
});

$stream->on('data', function ($data) {
    var_dump($data);
    ProcessManager::instance()->terminate();
});

```

### once and close

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Reactphp\Framework\Process\Process;
$stream = ProcessManager::instance()->callback(function () {
    return 'hello world';
}, true); // true after run close

$stream->on('data', function ($data) {
    var_dump($data);
});
```

## multiple  instance

```php
ProcessManager::instance('task')->initProcessNumber(10);
ProcessManager::instance('task')->callback(function($stream){
    return 'task handle'
});

ProcessManager::instance('cron')->initProcessNumber(5);
ProcessManager::instance('cron')->callback(function($stream){
    return 'cron process handle'
});
```

## config

### number

process number

```
ProcessManager::instance()->initProcessNumber(4);
```

### log and debug

```
ProcessManager::instance()->log = true;
ProcessManager::instance()->debug = true;
```

### reload 

```
ProcessManager::instance()->reload();
```

### restart

```
ProcessManager::instance()->restart();
```

### terminate

```
ProcessManager::instance()->terminate();
```

### close

```
ProcessManager::instance()->close();
```

## test

```
./vendor/bin/phpunit tests
```

# License

MIT
