# Process Pool Package

## Creating a Pool
```php
$minPoolSize = 1;
$maxPoolSize = 10;
poolProcessCmd = 'php process.php';
$cwd = '/path/to/pool/process';
$pool = new ProcessPool($minPoolSize, $maxPoolSize, $poolProcessCmd, $cwd);
$pool->setMaxNumSpareProcesses(3);
```

## Implementing a Pool Process
The pool process should implement `ssigwart\ProcessPool\ProcessPoolProcessMessageHandlerInterface`.
The `handleRequest` function should handle incoming requests.
It can output to `stdout` and `stderr`, which can be read with `getStdoutResponse` and `getStderrResponse`.
