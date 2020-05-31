<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

use ssigwart\ProcessPool\ProcessPool;
use ssigwart\ProcessPool\ProcessPoolPoolExhaustedException;
use ssigwart\ProcessPool\ProcessPoolUnexpectedEOFException;

/**
 * Process pool test
 */
final class ProcessPoolTest extends TestCase
{
	/**
	 * Test invalid response
	 */
	public function testInvalidResponse(): void
	{
		$this->expectException(ProcessPoolUnexpectedEOFException::class);
		$pool = new ProcessPool(1, 1, 'sleep 0', realpath(__DIR__ . DIRECTORY_SEPARATOR . '..'));
	}

	/**
	 * Test process pool
	 */
	public function testProcessPool(): void
	{
		$poolSize = 3;
		$pool = new ProcessPool(1, $poolSize, 'php processes/phpUnitProcesses.php', realpath(__DIR__ . DIRECTORY_SEPARATOR . '..'));

		// Single process
		$req1 = $pool->startProcess();
		$req1->sendRequest('Testing 1');
		$this->assertEquals('3560b3b3658d3f95d320367b007ee2b6', $req1->getStdoutResponse(), 'MD5 incorrect.');
		$pool->releaseProcess($req1);

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
			$requests[] = $req;
		}
		reset($requests);
		$req = reset($requests);
		foreach ($msgs as $md5=>$msg)
		{
			$this->assertEquals($md5, $req->getStdoutResponse(), 'MD5 incorrect.');
			$pool->releaseProcess($req);
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
				$this->assertEquals($poolSize, count($requests));
			}
		}
		$this->assertEquals($poolSize, count($requests));
		reset($requests);
		foreach ($requests as $req)
		{
			$this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/AD', $req->getStdoutResponse(), 'Response is not an MD5.');
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
		$this->assertEquals('3d719f5f6edb9ce45a82b527f0ea8a64', $req1->getStdoutResponse(), 'MD5 incorrect.');
		$this->assertEquals('Error-3d719f5f6edb9ce45a82b527f0ea8a64', trim($req1->getStderrResponse()), 'MD5 incorrect.');
		$pool->releaseProcess($req1);
		$this->assertEquals('3560b3b3658d3f95d320367b007ee2b6', $req2->getStdoutResponse(), 'MD5 incorrect.');
		// Make sure pool 1 can still process a valid response
		$req1 = $pool->startProcess();
		$req1->sendRequest('Testing 1');
		$this->assertEquals('3560b3b3658d3f95d320367b007ee2b6', $req1->getStdoutResponse(), 'MD5 incorrect.');
		$pool->releaseProcess($req1);
		$pool->releaseProcess($req2);
	}

	/**
	 * Test pool release before read
	 */
	public function testProcessPoolNoRelease(): void
	{
		$poolSize = 1;
		$pool = new ProcessPool($poolSize, $poolSize, 'php processes/phpUnitProcesses.php', realpath(__DIR__ . DIRECTORY_SEPARATOR . '..'));

		$req1 = $pool->startProcess();
		$req1->sendRequest('Error 1');
		$pool->releaseProcess($req1);
		$req1 = $pool->startProcess();
		$req1->sendRequest('Testing 1');
		$this->assertEquals('3560b3b3658d3f95d320367b007ee2b6', $req1->getStdoutResponse(), 'MD5 incorrect.');
		$pool->releaseProcess($req1);
	}
}
