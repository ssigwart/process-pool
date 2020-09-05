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
			$this->process = $proc;
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
		fwrite($this->pipes[0], ProcessPoolMessageTypes::MSG_START_REQUEST . ';' . strlen($data) . PHP_EOL . $data);
		fflush($this->pipes[0]);
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
	/** Stderr buffer */
	private $stderrBuffer = '';

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
		while (strlen($this->stdoutBuffer) < $length)
		{
			$newInput = $this->_getResponseFromPipe(1);
			$this->stdoutBuffer .= $newInput;

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
		while ($this->hasStderrData())
		{
			$lastData = $this->_getResponseFromPipe(2);
			$this->stderrBuffer .= $lastData;
		}
		return $this->stderrBuffer;
	}

	/**
	 * Get response from pipe
	 *
	 * @param int $pipeIdx Pipe index
	 *
	 * @return string Response
	 * @throws ProcessPoolException
	 */
	private function _getResponseFromPipe(int $pipeIdx): string
	{
		if ($this->process === null)
			throw new ProcessPoolResourceFailedException();

		// Read some data
		$input = fread($this->pipes[$pipeIdx], 1024);
		if ($input === false)
		{
			// Don't let the process be reuse
			$this->failed = true;
			throw new ProcessPoolUnexpectedEOFException();
		}

		return $input;
	}
}
