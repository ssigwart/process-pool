<?php

namespace TestAuxFiles;

use ssigwart\ProcessPool\ProcessPoolProcessMessageHandlerInterface;

/** PHP unit test process */
class PhpUnitTestProcess implements ProcessPoolProcessMessageHandlerInterface
{
	/** @var int Number of requests */
	private int $numRequests = 0;

	/**
	 * Handle exit request
	 */
	public function handleExit()
	{
		exit;
	}

	/**
	 * Handle request
	 *
	 * @param string $data Data
	 */
	public function handleRequest(string $data)
	{
		$this->numRequests++;

		// Check if we should exit
		if ($data === 'exit')
		{
			print 'exiting';
			exit;
		}
		else if (preg_match('/^exit-text-(.+)$/ADm', $data, $match))
		{
			print $match[1];
			exit;
		}
		else if ($data === 'exit-silent')
			exit;

		// Check if we should sleep
		if (preg_match('/^Sleep ([0-9]+(?:\\.[0-9]+)?)$/AD', $data, $match))
			usleep((int)((float)$match[1] * 1000000));

		// Check if we should put error message
		if (preg_match('/^ErrorOnly((?:.|\s)*)$/AD', $data, $match))
		{
			error_log('ErrorOnly-' . md5($match[1]));
			return;
		}
		else if (preg_match('/^Error/A', $data, $match))
			error_log('Error-' . md5($data));

		// Check if we should echo data
		if (preg_match('/^echo/A', $data, $match))
			print $data;
		// Check if we should echo data to stderr
		else if (preg_match('/^stderr echo/A', $data, $match))
			fwrite(STDERR, $data);
		// Check if we should output request count
		else if ($data === 'req-count')
			print $this->numRequests;
		// Check if we should test error with late STDOUT
		else if ($data === 'error-late-stdout')
		{
			fwrite(STDERR, 'Error, then sleep.');
			usleep(20000);
			print 'Done sleep';
		}
		else
		{
			// Output MD5 of data
			print md5($data);
		}
	}
}
