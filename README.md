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

min and max process number

```php
<?php

require __DIR__ . '/../vendor/autoload.php';

use Reactphp\Framework\Process\ProcessManager;
use React\EventLoop\Loop;

ProcessManager::instance()->initProcessNumber(5);
// ProcessManager::instance()->log = true;
// ProcessManager::instance()->debug = true;
ProcessManager::instance()->setCheckCycle(2);
ProcessManager::instance()->setOverTime(5);
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


```

## config

### number

process number

```
// default min and max = number
ProcessManager::instance()->initProcessNumber(4);
```

### min and max
process min number and max number

```
ProcessManager::instance()->setMin(1);
ProcessManager::instance()->setMax(10);
```

### check cycle and over time

check process active time to close over time process and up new process

```
ProcessManager::instance()->setCheckCycle(10);
ProcessManager::instance()->setOverTime(60);
```

### log and debug

```
ProcessManager::instance()->log = true;
ProcessManager::instance()->debug = true;

// callback output use error_log('hello world');
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
