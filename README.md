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

use Reactphp\Framework\Process\Process;

$stream = Process::callback(function () {
    return 'hello world';
});

$stream->on('data', function ($data) {
    var_dump($data);
    Process::terminate();
});
```

### stream

```php
require __DIR__ . '/vendor/autoload.php';

use Reactphp\Framework\Process\Process;
use React\EventLoop\Loop;

$stream = Process::callback(function ($stream) {
    $stream->end('hello world');
    return $stream;
});

$stream->on('data', function ($data) {
    var_dump($data);
    Process::terminate();
});

```

### once and close

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Reactphp\Framework\Process\Process;

$stream = Process::callback(function () {
    return 'hello world';
}, true); // true after run close

$stream->on('data', function ($data) {
    var_dump($data);
});
```

## config

### number

process number

```
Process::$number = 4;
```

### log and debug

```
Process::$log = true;
Process::$debug = true;
```

### reload 

```
Process::reload();
```

### restart

```
Process::restart();
```

### terminate

```
Process::terminate();
```

### close

```
Process::close();
```

## test

```
./vendor/bin/phpunit tests
```

# License

MIT