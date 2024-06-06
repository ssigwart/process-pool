<?php

namespace ssigwart\ProcessPool;

/** Process pool request */
class ProcessPoolRequest
{
	/** @var resource|null Process */
	private $process = null;

	/** @var array Pipes */
	private $pipes = null;

	/** @var bool Process failed? */
	private $failed = false;

	/**
	 * Constructor
	 *
	 * @param string $cmd Command to run
	 * @param string|null $cwd Working directory
	 * @param array|null $env Hash of environment variable to value
	 */
	public function __construct(string $cmd, ?string $cwd = null, ?array $env = null)
	{
		$descriptorSpec = [
			0 => ['pipe', 'r'],
			1 => ['pipe', 'w'],
			2 => ['pipe', 'w']
		];
		$proc = proc_open($cmd, $descriptorSpec, $this->pipes, $cwd, $env);
		if ($proc !== false)
		{
			$this->process = $proc;

			// Make sure `fread` doesn't block for stderr reads
			stream_set_blocking($this->pipes[2], false);
		}
	}

	/**
	 * Destructor
	 */
	public function __destruct()
	{
		$this->freeRequest();
		$this->close();
	}

	/**
	 * Write message
	 *
	 * @param string $msg Message
	 */
	private function writeMsg(string $msg): void
	{
		// Write data
		$bytesWritten = fwrite($this->pipes[0], $msg);
		if ($bytesWritten === false || $bytesWritten === 0)
		{
			$exceptionMsg = 'Failed to write message.';
			$error = error_get_last();
			if ($error !== null)
				$exceptionMsg .= ' ' . $error['message'];
			throw new ProcessPoolException($exceptionMsg, $error['type'] ?? 0);
		}

		// Write more data
		if ($bytesWritten < strlen($msg))
			$this->writeMsg(substr($msg, $bytesWritten));
		// Flush when done
		else
			fflush($this->pipes[0]);
	}

	/**
	 * Send a request
	 *
	 * @param string $data Data to send
	 * @throws ProcessPoolException
	 */
	public function sendRequest(string $data): void
	{
		if ($this->process === null)
			throw new ProcessPoolResourceFailedException();

		// Write data
		$this->writeMsg(ProcessPoolMessageTypes::MSG_START_REQUEST . ';' . strlen($data) . PHP_EOL . $data);
	}

	/**
	 * Send exit request
	 *
	 * @throws ProcessPoolException
	 */
	public function sendExitRequest(): void
	{
		if ($this->process === null)
			throw new ProcessPoolResourceFailedException();

		// Write data
		$this->writeMsg(ProcessPoolMessageTypes::MSG_EXIT . ';' . PHP_EOL);
	}

	/**
	 * Free request
	 *
	 * @throws ProcessPoolException
	 */
	public function freeRequest(): void
	{
		// Make sure we read all data
		if ($this->process !== null)
		{
			if ($this->stdoutBuffer === null)
				$this->getStdoutResponse();
			if ($this->hasStderrData())
				$this->getStderrResponse();
		}
	}

	/**
	 * Close process
	 */
	public function close(): void
	{
		if ($this->process !== null)
		{
			proc_close($this->process);
			$this->process = null;
		}
	}

	/**
	 * Did process fail?
	 *
	 * @return bool True if there was a failure
	 */
	public function hasFailed(): bool
	{
		return $this->failed;
	}

	/**
	 * Check if there's stdout data
	 *
	 * @return bool True if there's data
	 * @throws ProcessPoolException
	 */
	public function hasStdoutData(): bool
	{
		return $this->_hasPipeData(1);
	}

	/**
	 * Check if there's stderr data
	 *
	 * @return bool True if there's data
	 * @throws ProcessPoolException
	 */
	public function hasStderrData(): bool
	{
		return $this->_hasPipeData(2);
	}

	/**
	 * Check if there's data in a pipe
	 *
	 * @param int $pipeIdx Pipe index
	 * @param int $waitSec Number of seconds to wait
	 * @param int $waitUsec Number of microseconds to wait in addition to seconds
	 *
	 * @return bool True if there's data
	 * @throws ProcessPoolException
	 */
	private function _hasPipeData(int $pipeIdx, int $waitSec = 0, int $waitUsec = 0): string
	{
		if ($this->process === null)
			throw new ProcessPoolResourceFailedException();
		$read = [$this->pipes[$pipeIdx]];
		$write = [];
		$except = [];
		return stream_select($read, $write, $except, $waitSec, $waitUsec) > 0;
	}

	/** Stdout buffer */
	private $stdoutBuffer = null;

	/**
	 * Get stdout response
	 *
	 * @return string Response
	 * @throws ProcessPoolException
	 */
	public function getStdoutResponse(): string
	{
		$this->stdoutBuffer = '';
		$readLen = 0;
		// Get message length
		while (!preg_match('/^([0-9]+);/', $this->stdoutBuffer, $match))
		{
			if (preg_match('/[^0-9;]/', $this->stdoutBuffer) || substr($this->stdoutBuffer, 0, 1) === ';')
				throw new ProcessPoolUnexpectedMessageException();
			$this->stdoutBuffer .= $this->_getResponseFromPipe(1);
			$newReadLen = strlen($this->stdoutBuffer);
			if ($newReadLen === 0)
				throw new ProcessPoolUnexpectedEOFException();
			else if ($readLen === $newReadLen)
				throw new ProcessPoolUnexpectedMessageException();
			$readLen = $newReadLen;
		}
		$length = (int)$match[1];
		$this->stdoutBuffer = substr($this->stdoutBuffer, strlen($match[1]) + 1);

		// Get response
		$numBytesMoreToRead = $length - strlen($this->stdoutBuffer);
		while ($numBytesMoreToRead > 0)
		{
			$newInput = $this->_getResponseFromPipe(1, $numBytesMoreToRead);
			$this->stdoutBuffer .= $newInput;
			$numBytesMoreToRead = $length - strlen($this->stdoutBuffer);

			// Make sure not at EOF
			if ($newInput === '')
				throw new ProcessPoolUnexpectedEOFException();
		}
		$rtn = substr($this->stdoutBuffer, 0, $length);
		$this->stdoutBuffer = substr($this->stdoutBuffer, $length);
		return $rtn;
	}

	/**
	 * Get stderr response
	 *
	 * @return string Response
	 * @throws ProcessPoolException
	 */
	public function getStderrResponse(): string
	{
		$rtn = '';
		while ($this->hasStderrData())
		{
			$lastData = $this->_getResponseFromPipe(2);
			$rtn .= $lastData;
		}
		return $rtn;
	}

	/**
	 * Get response from pipe
	 *
	 * @param int $pipeIdx Pipe index
	 * @param int|null $maxNumBytes Max number of bytes
	 *
	 * @return string Response
	 * @throws ProcessPoolException
	 */
	private function _getResponseFromPipe(int $pipeIdx, ?int $maxNumBytes = null): string
	{
		if ($this->process === null)
			throw new ProcessPoolResourceFailedException();

		// Read some data
		$input = fread($this->pipes[$pipeIdx], min($maxNumBytes ?? 1024, 1024));
		if ($input === false)
		{
			// Don't let the process be reuse
			$this->failed = true;
			throw new ProcessPoolUnexpectedEOFException();
		}

		return $input;
	}
}
