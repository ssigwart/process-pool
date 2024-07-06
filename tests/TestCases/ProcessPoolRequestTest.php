<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

use ssigwart\ProcessPool\ProcessPoolRequest;

/**
 * Process pool request test
 */
final class ProcessPoolRequestTest extends TestCase
{
	/**
	 * Test wait for stdout
	 */
	public function testWaitForStdout(): void
	{
		$cmd = 'php processes/phpUnitProcesses.php';
		$cwd = realpath(__DIR__ . DIRECTORY_SEPARATOR . '..');
		$req1 = new ProcessPoolRequest($cmd, $cwd, null);
		$msg = "Hello\nworld!";
		$req1->sendRequest($msg);
		$req1->waitForStdoutOrStderr(1, 500000);
		self::assertEquals(true, $req1->hasStdoutData(), 'Stdout should have data.');
		self::assertEquals(false, $req1->hasStderrData(), 'Stderr should not have data.');
		self::assertEquals(md5($msg), $req1->getStdoutResponse(), 'Stdout does not match.');
	}

	/**
	 * Test wait for stdout or stderr
	 */
	public function testWaitForStderr(): void
	{
		$cmd = 'php processes/phpUnitProcesses.php';
		$cwd = realpath(__DIR__ . DIRECTORY_SEPARATOR . '..');
		$req1 = new ProcessPoolRequest($cmd, $cwd, null);
		$msg = "Hello\nworld!";
		$req1->sendRequest('ErrorOnly' . $msg);
		$req1->waitForStdoutOrStderr(1, 500000);
		if ($req1->hasStdoutData())
			self::assertEquals('', $req1->getStdoutResponse(), 'Stdout should be empty string.');
		self::assertEquals(true, $req1->hasStderrData(), 'Stderr should have data.');
		self::assertEquals('ErrorOnly-' . md5($msg) . PHP_EOL, $req1->getStderrResponse(), 'Stderr does not match.');
	}

	/**
	 * Test pool process invalid message
	 */
	public function testProcessPoolInvalidMessage(): void
	{
		$cmd = 'php processes/phpUnitProcesses.php';
		$cwd = realpath(__DIR__ . DIRECTORY_SEPARATOR . '..');
		$req1 = new ProcessPoolRequest($cmd, $cwd, null);
		$reflectionClass = new ReflectionClass($req1);
		$reflectionClass->getMethod('writeMsg')->invoke($req1, '9999;' . PHP_EOL);
		for ($i = 0; $i < 100 && !$req1->hasStdoutData() && !$req1->hasStderrData(); $i++)
			usleep(10000);
		// Add sleep to also test `hasStdoutData` returning true on EOF
		usleep(100000);
		self::assertEquals(true, $req1->hasStderrData(), 'Stderr expected.');
		self::assertEquals('Caught Exception: ssigwart\ProcessPool\ProcessPoolUnexpectedMessageException: 0 - Invalid message type "9999".' . PHP_EOL, $req1->getStderrResponse());
		// Allow stdin
		if ($req1->hasStdoutData())
			self::assertEquals('', $req1->getStdoutResponse(), 'Stdout expected to be empty string if there was data.');
		for ($i = 0; $i < 100 && proc_get_status($reflectionClass->getProperty('process')->getValue($req1))['running']; $i++)
			usleep(10000);
		self::assertEquals(false, proc_get_status($reflectionClass->getProperty('process')->getValue($req1))['running']);
	}

	/**
	 * Test pool process parent closed input
	 */
	public function testProcessPoolParentClosedInput(): void
	{
		$cmd = 'php processes/phpUnitProcesses.php';
		$cwd = realpath(__DIR__ . DIRECTORY_SEPARATOR . '..');
		$req1 = new ProcessPoolRequest($cmd, $cwd, null);
		$reflectionClass = new ReflectionClass($req1);
		$pipes = $reflectionClass->getProperty('pipes')->getValue($req1);
		fclose($pipes[0]);
		for ($i = 0; $i < 100 && !$req1->hasStderrData(); $i++)
			usleep(10000);
		self::assertEquals(true, $req1->hasStderrData(), 'Stderr expected.');
		self::assertEquals('Caught Exception: ssigwart\ProcessPool\ProcessPoolUnexpectedEOFExceptionWhileWaitingForRequest: 0 - EOF while waiting for request.' . PHP_EOL, $req1->getStderrResponse());
		for ($i = 0; $i < 100 && proc_get_status($reflectionClass->getProperty('process')->getValue($req1))['running']; $i++)
			usleep(10000);
		self::assertEquals(false, proc_get_status($reflectionClass->getProperty('process')->getValue($req1))['running']);
		// self::assertEqKEquals(true, $req1->hasFailed());
	}
}
