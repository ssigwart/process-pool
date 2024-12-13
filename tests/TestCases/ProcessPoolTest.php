<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

use ssigwart\ProcessPool\ProcessPool;
use ssigwart\ProcessPool\ProcessPoolException;
use ssigwart\ProcessPool\ProcessPoolPoolExhaustedException;
use ssigwart\ProcessPool\ProcessPoolProcessOutputBeforeStartingException;

/**
 * Process pool test
 */
final class ProcessPoolTest extends TestCase
{
	/**
	 * Test invalid max spares
	 */
	public function testInvalidMaxSpares(): void
	{
		$pool = new ProcessPool(5, 10, 'php processes/phpUnitProcesses.php', realpath(__DIR__ . DIRECTORY_SEPARATOR . '..'));
		$this->expectException(ProcessPoolException::class);
		$pool->setMaxNumSpareProcesses(3);
	}

	/**
	 * Test process pool
	 */
	public function testProcessPool(): void
	{
		$minPoolSize = 1;
		$poolSize = 3;
		$pool = new ProcessPool($minPoolSize, $poolSize, 'php processes/phpUnitProcesses.php', realpath(__DIR__ . DIRECTORY_SEPARATOR . '..'));
		$maxSpares = 2;
		$pool->setMaxNumSpareProcesses($maxSpares);

		// Single process
		$numRunning = 0;
		$numUnassigned = $minPoolSize;
		$req1 = $pool->startProcess();
		$req1->sendRequest('Testing 1');
		$numRunning++;
		$numUnassigned--;
		if ($numUnassigned < 0)
			$numUnassigned = 0;
		self::assertEquals($numRunning, $pool->getNumRunningProcesses(), 'Number of processes running incorrect.');
		self::assertEquals($numUnassigned, $pool->getNumUnassignedProcesses(), 'Number of processes unassigned incorrect.');
		self::assertEquals('3560b3b3658d3f95d320367b007ee2b6', $req1->getStdoutResponse(), 'MD5 incorrect.');
		self::assertEquals('', $req1->getStderrResponse(), 'Stderr should be empty.');
		$pool->releaseProcess($req1);
		$numRunning--;
		$numUnassigned++;

		// Multiple processes
		$msgs = [
			'ff31a38128f8c1539db012ce1dcafc3a' => 'Multiple 1',
			'4f0d104aaafed37cf4c906b40356baf8' => 'Multiple 2',
			'0ee4a41bac6fb69853332dc185c7081e' => 'Multiple 3'
		];
		$requests = [];
		foreach ($msgs as $msg)
		{
			$req = $pool->startProcess();
			$req->sendRequest($msg);
			$numRunning++;
			$numUnassigned--;
			if ($numUnassigned < 0)
				$numUnassigned = 0;
			self::assertEquals($numRunning, $pool->getNumRunningProcesses(), 'Number of processes running incorrect.');
			self::assertEquals($numUnassigned, $pool->getNumUnassignedProcesses(), 'Number of processes unassigned incorrect.');
			$requests[] = $req;
		}
		reset($requests);
		$req = reset($requests);
		foreach ($msgs as $md5=>$msg)
		{
			self::assertEquals($md5, $req->getStdoutResponse(), 'MD5 incorrect.');
			self::assertEquals('', $req1->getStderrResponse(), 'Stderr should be empty.');
			$pool->releaseProcess($req);
			$numRunning--;
			$numUnassigned++;
			self::assertEquals($numRunning, $pool->getNumRunningProcesses(), 'Number of processes running incorrect.');
			self::assertEquals(min($maxSpares, $numUnassigned), $pool->getNumUnassignedProcesses(), 'Number of processes unassigned incorrect.');
			$req = next($requests);
		}

		// Processes exhausted
		$msgs = [];
		for ($i = 0; $i < $poolSize + 1; $i++)
			$msgs[] = 'Sleep 0.1';
		$requests = [];
		foreach ($msgs as $msg)
		{
			try
			{
				$req = $pool->startProcess();
				$req->sendRequest($msg);
				$requests[] = $req;
			} catch (ProcessPoolPoolExhaustedException $e) {
				self::assertEquals($poolSize, count($requests));
			}
		}
		self::assertEquals($poolSize, count($requests));
		reset($requests);
		foreach ($requests as $req)
		{
			$this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/AD', $req->getStdoutResponse(), 'Response is not an MD5.');
			self::assertEquals('', $req1->getStderrResponse(), 'Stderr should be empty.');
			$pool->releaseProcess($req);
		}
	}

	/**
	 * Test process pool errors
	 */
	public function testProcessPoolErrors(): void
	{
		$poolSize = 2;
		$pool = new ProcessPool($poolSize, $poolSize, 'php processes/phpUnitProcesses.php', realpath(__DIR__ . DIRECTORY_SEPARATOR . '..'));

		// 1 error
		$req1 = $pool->startProcess();
		$req1->sendRequest('Error 1');
		$req2 = $pool->startProcess();
		$req2->sendRequest('Testing 1');
		self::assertEquals('3d719f5f6edb9ce45a82b527f0ea8a64', $req1->getStdoutResponse(), 'MD5 incorrect.');
		self::assertEquals('Error-3d719f5f6edb9ce45a82b527f0ea8a64', trim($req1->getStderrResponse()), 'Stderr incorrect.');
		$pool->releaseProcess($req1);
		self::assertEquals('3560b3b3658d3f95d320367b007ee2b6', $req2->getStdoutResponse(), 'MD5 incorrect.');
		self::assertEquals('', $req2->getStderrResponse(), 'Stderr should be empty.');
		// Make sure pool 1 can still process a valid response
		$req1 = $pool->startProcess();
		$req1->sendRequest('Testing 1');
		self::assertEquals('3560b3b3658d3f95d320367b007ee2b6', $req1->getStdoutResponse(), 'MD5 incorrect.');
		self::assertEquals('', trim($req1->getStderrResponse()), 'Stderr incorrect.');
		$pool->releaseProcess($req1);
		$pool->releaseProcess($req2);
	}

	/**
	 * Test process pool marked as failed
	 */
	public function testProcessPoolMarkedAsFailed(): void
	{
		$poolSize = 1;
		$pool = new ProcessPool($poolSize, $poolSize, 'php processes/phpUnitProcesses.php', realpath(__DIR__ . DIRECTORY_SEPARATOR . '..'));

		$req = $pool->startProcess();
		$req->sendRequest('req-count');
		self::assertEquals('1', $req->getStdoutResponse(), 'MD5 incorrect.');
		$pool->releaseProcess($req);

		$req = $pool->startProcess();
		$req->sendRequest('req-count');
		self::assertEquals('2', $req->getStdoutResponse(), 'MD5 incorrect.');
		$pool->releaseProcess($req);

		$req = $pool->startProcess();
		$req->sendRequest('req-count');
		self::assertEquals('3', $req->getStdoutResponse(), 'MD5 incorrect.');
		$req->markAsFailed();
		$pool->releaseProcess($req);

		$req = $pool->startProcess();
		$req->sendRequest('req-count');
		self::assertEquals('1', $req->getStdoutResponse(), 'MD5 incorrect.');
		$pool->releaseProcess($req);

		$req = $pool->startProcess();
		$req->sendRequest('req-count');
		self::assertEquals('2', $req->getStdoutResponse(), 'MD5 incorrect.');
		$pool->releaseProcess($req);
	}

	/**
	 * Test process pool error before response
	 */
	public function testProcessPoolErrorBeforeResponse(): void
	{
		$poolSize = 1;
		$pool = new ProcessPool($poolSize, $poolSize, 'php processes/phpUnitProcesses.php', realpath(__DIR__ . DIRECTORY_SEPARATOR . '..'));

		$req = $pool->startProcess();
		$req->sendRequest('req-count');
		self::assertEquals('1', $req->getStdoutResponse(), 'MD5 incorrect.');
		$pool->releaseProcess($req);

		$req = $pool->startProcess();
		$req->sendRequest('req-count');
		self::assertEquals('2', $req->getStdoutResponse(), 'MD5 incorrect.');
		$pool->releaseProcess($req);

		$req = $pool->startProcess();
		$req->sendRequest('error-late-stdout');
		usleep(5000);
		self::assertEquals('Error, then sleep.', $req->getStderrResponse(), 'MD5 incorrect.');
		self::assertEquals(false, $req->hasStdoutData(), 'No STDOUT expected.');
		$pool->releaseProcess($req);
		usleep(250000);

		try {
			$req = $pool->startProcess();
			self::fail('Expected ProcessPoolProcessOutputBeforeStartingException.');
		} catch (ProcessPoolProcessOutputBeforeStartingException $e) {
			self::assertEquals([], $e->getStderrLines());
			self::assertEquals(['Done sleep'], $e->getStdoutLines());
		}
		$req = $pool->startProcess();
		$req->sendRequest('req-count');
		self::assertEquals('1', $req->getStdoutResponse(), 'MD5 incorrect.');
		$pool->releaseProcess($req);

		$req = $pool->startProcess();
		$req->sendRequest('req-count');
		self::assertEquals('2', $req->getStdoutResponse(), 'MD5 incorrect.');
		$pool->releaseProcess($req);
	}

	/**
	 * Test pool release before read
	 */
	public function testProcessPoolNoReadBeforeRead(): void
	{
		$poolSize = 1;
		$pool = new ProcessPool($poolSize, $poolSize, 'php processes/phpUnitProcesses.php', realpath(__DIR__ . DIRECTORY_SEPARATOR . '..'));

		$req1 = $pool->startProcess();
		$req1->sendRequest('Error 1');
		$req1->waitForStdoutOrStderr(1); // Note: Without this, releasing may not think there's data
		$pool->releaseProcess($req1);
		$req1 = $pool->startProcess();
		$req1->sendRequest('Testing 1');
		self::assertEquals('3560b3b3658d3f95d320367b007ee2b6', $req1->getStdoutResponse(), 'MD5 incorrect.');
		self::assertEquals('', $req1->getStderrResponse(), 'Stderr should be empty.');
		$pool->releaseProcess($req1);
	}

	/**
	 * Test pool release with long message
	 */
	public function testProcessPoolWithLongMessage(): void
	{
		$poolSize = 1;
		$pool = new ProcessPool($poolSize, $poolSize, 'php processes/phpUnitProcesses.php', realpath(__DIR__ . DIRECTORY_SEPARATOR . '..'));

		$req1 = $pool->startProcess();
		$data = str_repeat('0123456789', 120);
		$req1->sendRequest($data);
		self::assertEquals(md5($data), $req1->getStdoutResponse(), 'MD5 incorrect.');
		self::assertEquals('', $req1->getStderrResponse(), 'Stderr should be empty.');
		$pool->releaseProcess($req1);
	}

	/**
	 * Test pool release with long response
	 */
	public function testProcessPoolWithLongResponse(): void
	{
		$poolSize = 1;
		$pool = new ProcessPool($poolSize, $poolSize, 'php processes/phpUnitProcesses.php', realpath(__DIR__ . DIRECTORY_SEPARATOR . '..'));

		$req1 = $pool->startProcess();
		$data = 'echo ' . str_repeat('0123456789', 120);
		$req1->sendRequest($data);
		self::assertEquals($data, $req1->getStdoutResponse(), 'MD5 incorrect.');
		self::assertEquals('', $req1->getStderrResponse(), 'Stderr should be empty.');
		$pool->releaseProcess($req1);
	}

	/**
	 * Test pool release with long STDERR response
	 */
	public function testProcessPoolWithLongStderrResponse(): void
	{
		$poolSize = 1;
		$pool = new ProcessPool($poolSize, $poolSize, 'php processes/phpUnitProcesses.php', realpath(__DIR__ . DIRECTORY_SEPARATOR . '..'));

		$req1 = $pool->startProcess();
		$data = 'stderr echo ' . str_repeat('0123456789', 120);
		$req1->sendRequest($data);
		self::assertEquals('', $req1->getStdoutResponse(), 'MD5 incorrect.');
		self::assertEquals($data, $req1->getStderrResponse(), 'Stderr should be empty.');
		$pool->releaseProcess($req1);
	}

	/**
	 * Test pool process exit
	 */
	public function testProcessPoolExit(): void
	{
		$poolSize = 1;
		$pool = new ProcessPool($poolSize, $poolSize, 'php processes/phpUnitProcesses.php', realpath(__DIR__ . DIRECTORY_SEPARATOR . '..'));

		$req1 = $pool->startProcess();
		$data = 'exit';
		$req1->sendRequest($data);
		self::assertEquals('exiting', $req1->getStdoutResponse());
		$pool->releaseProcess($req1);
	}

	/**
	 * Test pool process silent exit
	 */
	public function testProcessPoolSilentExit(): void
	{
		$poolSize = 1;
		$pool = new ProcessPool($poolSize, $poolSize, 'php processes/phpUnitProcesses.php', realpath(__DIR__ . DIRECTORY_SEPARATOR . '..'));

		$req1 = $pool->startProcess();
		$data = 'exit-silent';
		$req1->sendRequest($data);
		self::assertEquals('', $req1->getStdoutResponse(), 'Stdout expected to be empty string.');
		$pool->releaseProcess($req1);
	}

	/**
	 * Test pool process exit hang
	 */
	public function testProcessPoolExitHang(): void
	{
		$poolSize = 1;
		$pool = new ProcessPool($poolSize, $poolSize, 'php processes/phpUnitProcesses.php', realpath(__DIR__ . DIRECTORY_SEPARATOR . '..'));

		$req1 = $pool->startProcess();
		$data = 'exit-text-100;abc' . PHP_EOL; // This will exit with "100;abc\n", which should not be interpreted as data of length 100
		$req1->sendRequest($data);
		self::assertEquals('100;abc', $req1->getStdoutResponse());
		$pool->releaseProcess($req1);
	}

	/**
	 * Test pool manual shutdown
	 */
	public function testProcessPoolShutDown(): void
	{
		$poolSize = 3;
		$pool = new ProcessPool($poolSize, $poolSize, 'php processes/phpUnitProcesses.php', realpath(__DIR__ . DIRECTORY_SEPARATOR . '..'));
		self::assertEquals($poolSize, $pool->getNumUnassignedProcesses(), 'Number of processes unassigned should be ' . $poolSize . '.');

		$req1 = $pool->startProcess();
		$req1->sendRequest('Testing 1');
		self::assertEquals('3560b3b3658d3f95d320367b007ee2b6', $req1->getStdoutResponse(), 'MD5 incorrect.');
		self::assertEquals('', $req1->getStderrResponse(), 'Stderr should be empty.');
		self::assertEquals(1, $pool->getNumRunningProcesses(), 'Number of processes running should be 1.');
		$pool->releaseProcess($req1);

		self::assertEquals(0, $pool->getNumRunningProcesses(), 'Number of processes running should be 0.');
		self::assertEquals($poolSize, $pool->getNumUnassignedProcesses(), 'Number of processes unassigned should be ' . $poolSize . '.');

		$pool->shutDown();

		self::assertEquals(0, $pool->getNumRunningProcesses(), 'Number of processes running should be 0.');
		self::assertEquals(0, $pool->getNumUnassignedProcesses(), 'Number of processes unassigned should be 0.');
	}
}
