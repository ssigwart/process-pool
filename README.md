# Process Pool Package

This package allows you to run a process pool.
The pool will multiple background processes to handle messages to the pool.
You can specific the minimum and maximum number of pool processes to run.

## Creating a Pool
```php
$minPoolSize = 1;
$maxPoolSize = 10;
$poolProcessCmd = 'php process.php';
$cwd = '/path/to/pool/process';
$pool = new ProcessPool($minPoolSize, $maxPoolSize, $poolProcessCmd, $cwd);
$pool->setMaxNumSpareProcesses(3);
```

## Implementing a Pool Process
The pool process should implement `ssigwart\ProcessPool\ProcessPoolProcessMessageHandlerInterface`.
The `handleRequest` function should handle incoming requests.
It can output to `stdout` and `stderr`, which can be read with `getStdoutResponse` and `getStderrResponse`.

Below is an example of what `/path/to/pool/process/process.php` might look like.
```php
<?php

use ssigwart\ProcessPool\ProcessPoolProcess;
use ssigwart\ProcessPool\ProcessPoolProcessMessageHandlerInterface;

/** My example process */
class MyExampleProcess implements ProcessPoolProcessMessageHandlerInterface
{
	/**
	 * Handle exit request
	 */
	public function handleExit()
	{
		// You can do cleanup here, then call exit
		print 'Existing now.' . PHP_EOL;
		exit;
	}

	/**
	 * Handle request
	 *
	 * @param string $data Data
	 */
	public function handleRequest(string $data)
	{
		// Handle $data here

		// Anything you output will be returned as STDOUT
		print 'You sent data ' . $data . PHP_EOL;

		// You can use error_log to output to STDERR
		error_log('This will be returned as STDERR.');
	}
}

$proc = new ProcessPoolProcess(new MyExampleProcess());
$proc->handleMessages();
```

## Sending a Message to the Pool

1. Start a process
```php
$req1 = $pool->startProcess();
$req1->sendRequest('Your message');
```

2. At this point, you should add `$req1` to an array of pending requests and send any other message you need to the queue.
At some point in the future, you should check if and of those processes have data yet by calling `hasStdoutData()` and `hasStderrData()`.

3. Get output and release process so if can be used for another request
```
$output = $req1->getStdoutResponse();
$errorOutput = $req1->getStderrResponse();
$pool->releaseProcess($req1);
```


## Classes and Public API

### `ProcessPool`

The process pool is used to maintain the pool state and start and stop processes.

#### Constructor (`__construct`)
| Parameter | Type | Description |
| - | - | - |
| `$minNumProcs` | `int` | Minimum number of processes to run in the pool. |
| `$maxNumProcs` | `int` | Maximum number of processes to run in the pool. |
| `$cmd` | `string` | Command to start the background process to handle a request. |
| `$cwd` | `?string` | Optional working directory to use when starting the process. |
| `$env | `?array` | Optional hash of environment variables for the process |

#### `setMaxNumSpareProcesses`
Sets the maximum number of spare processes to leave running in the pool after they complete a request, but are waiting for a new request.

| Parameter | Type | Description |
| - | - | - |
| `$maxNumUnassignedProcs` | `int` | Maximum number of spare processes to run in the pool. |

#### `getNumRunningProcesses`
Returns the number of running processes.
A running process is one that is actively handling a request at the moment.

#### `getNumUnassignedProcesses`
Returns the number of unassigned processes.
An unassigned process is one that is waiting for a request to process.

#### `startProcess`
Starts a new process.

_IMPORTANT! Remember to call `releaseProcess` on the pool to free the resources of the process when you're done with it._

This may throw a `ProcessPoolPoolExhaustedException` if the pool has reached the maximum number of allowed processes.

It returns a `ProcessPoolRequest` that you can send your request to.

#### `releaseProcess`
This releases a process started with `startProcess` and allows it to go back into the spare process pool to handle additional requests.

| Parameter | Type | Description |
| - | - | - |
| `$process` | `ProcessPoolRequest` | Process to release. |

#### `shutDown`
This sends "exit" requests to all processes and closes them.
This should only be called after handling all open processes and the pool should not be used after this.
Often, you don't need to call this function as the pool registers a shutdown function.

### `ProcessPoolRequest`
Represents a pool request that can process a single request.

#### `sendRequest`

| Parameter | Type | Description |
| - | - | - |
| `$data` | `string` | Data you want to send to the process to handle. |

#### `hasFailed`

Returns true if the process handling the request has failed.

#### `waitForStdoutOrStderr`
Waits for data on STDOUT or STDERR.

| Parameter | Type | Description |
| - | - | - |
| `$waitSec` | `int` | Maximum number of seconds + `waitUsec` microseconds to wait. |
| `$waitUsec` | `int` | Maximum number of microseconds + `$waitSec` seconds to wait. |

The returns true if there's data available. If so, `hasStdoutData` and `hasStderrData` should be called.

#### `hasStdoutData`

Returns true if the request has STDOUT data available.
Note that this may return true when EOF, but the data returned from `getStdoutResponse` may be an empty string.

#### `hasStderrData`
Returns true if the request has STDERR data available.
Note that this may return true when EOF, but the data returned from `getStderrResponse` may be an empty string.

#### `getStdoutResponse`
Returns STDOUT response from the process.

#### `getStderrResponse`
Returns STDERR response from the process.

### Other Methods

There are additional public function that you should not call directly.
These include `sendExitRequest`, `freeRequest`, and `close`.

### `ProcessPoolProcess`
This is used by the background process to wait for and handle incoming messages from the pool.

#### Constructor (`__construct`)
| Parameter | Type | Description |
| - | - | - |
| `$handler` | `ProcessPoolProcessMessageHandlerInterface` | This is a custom class that you defined that will be able to handle an incoming request. |

#### `handleMessages`
This function does the work of waiting for message and returning output to the parent pool process.
