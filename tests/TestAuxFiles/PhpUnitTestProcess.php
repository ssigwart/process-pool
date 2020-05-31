<?php

namespace TestAuxFiles;

use ssigwart\ProcessPool\ProcessPoolProcessMessageHandlerInterface;

/** PHP unit test process */
class PhpUnitTestProcess implements ProcessPoolProcessMessageHandlerInterface
{
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
		// Check if we should sleep
		if (preg_match('/^Sleep ([0-9]+(?:\\.[0-9]+)?)$/AD', $data, $match))
			usleep((int)((float)$match[1] * 1000000));

		// Check if we should put error message
		if (preg_match('/^Error/A', $data, $match))
			error_log('Error-' . md5($data));

		// Return MD5 of data
		print md5($data);
	}
}
