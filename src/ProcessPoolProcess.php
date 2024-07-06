<?php

namespace ssigwart\ProcessPool;

use Throwable;

/** Process pool process */
class ProcessPoolProcess
{
	/** @var ProcessPoolProcessMessageHandlerInterface Message handler */
	private $handler = null;

	/** @var string Input buffer */
	private $inputBuffer = '';

	/** @var bool Is output buffer open? */
	private bool $isBufferOpen = false;

	/**
	 * Constructor
	 *
	 * @param ProcessPoolProcessMessageHandlerInterface $handler Message handler
	 */
	public function __construct(ProcessPoolProcessMessageHandlerInterface $handler)
	{
		$this->handler = $handler;
	}

	/**
	 * Destructor
	 */
	public function __destruct()
	{
		// Output buffer if needed. This can happen if the handle exited in `handleRequest`
		if ($this->isBufferOpen)
			$this->finalizeRequest();
	}

	/**
	 * Handle messages
	 */
	public function handleMessages()
	{
		while (true)
		{
			$this->waitForRequest();
			$this->finalizeRequest();
		}
	}

	/**
	 * Wait for a request
	 *
	 * @throws ProcessPoolException
	 */
	private function waitForRequest()
	{
		ob_start();
		$this->isBufferOpen = true;

		// Get message type
		try {
			$msgType = $this->_waitForNumberWithEndChar(';');
		} catch (ProcessPoolUnexpectedEOFException $e) {
			throw new ProcessPoolUnexpectedEOFExceptionWhileWaitingForRequest('EOF while waiting for request.', 0, $e);
		}

		// Process message type
		if ($msgType === ProcessPoolMessageTypes::MSG_START_REQUEST)
		{
			$length = $this->_waitForNumberWithEndChar(PHP_EOL);
			$numBytesMoreToRead = $length - strlen($this->inputBuffer);
			while ($numBytesMoreToRead > 0)
			{
				$input = fread(STDIN, min($numBytesMoreToRead, 1024));
				if ($input === false)
					throw new ProcessPoolUnexpectedEOFException();
				$this->inputBuffer .= $input;
				$numBytesMoreToRead = $length - strlen($this->inputBuffer);
			}
			$data = substr($this->inputBuffer, 0, $length);
			$this->inputBuffer = substr($this->inputBuffer, $length);

			// Handle request
			try {
				$this->handler->handleRequest($data);
			} catch (Throwable $e) {
				throw new ProcessPoolException('Failed to handle request.', 0, $e);
			}
		}
		else if ($msgType === ProcessPoolMessageTypes::MSG_EXIT)
			$this->handler->handleExit();
		else
			throw new ProcessPoolUnexpectedMessageException('Invalid message type "' . $msgType . '".');
	}

	/**
	 * Wait for a number followed by a delimiter character
	 *
	 * @return int Number
	 * @throws ProcessPoolException
	 */
	private function _waitForNumberWithEndChar(string $char)
	{
		while (true)
		{
			if ($this->inputBuffer !== '')
			{
				// Do we have a message type
				$pos = strpos($this->inputBuffer, $char);
				if ($pos !== false)
				{
					$msgTypePart = substr($this->inputBuffer, 0, $pos);
					$this->inputBuffer = substr($this->inputBuffer, $pos + 1);
					if (!preg_match('/^[0-9]+$/AD', $msgTypePart))
						throw new ProcessPoolUnexpectedMessageException('Input buffer: ' . $msgTypePart);
					return (int)$msgTypePart;
				}
				// Message should start with a number
				else if (!preg_match('/^[0-9]*$/AD', $this->inputBuffer))
					throw new ProcessPoolUnexpectedMessageException('Input buffer: ' . substr($this->inputBuffer, 0, 64));
			}

			// Get more input. Note that we expect a new line after message types, so we can expect fread to exit before 1024 characters.
			$input = fread(STDIN, 1024);
			if ($input === false || ($input === '' && feof(STDIN)))
				throw new ProcessPoolUnexpectedEOFException();
			$this->inputBuffer .= $input;
		}
	}

	/**
	 * Finalize request
	 *
	 * @throws ProcessPoolException
	 */
	private function finalizeRequest()
	{
		$resp = ob_get_clean();
		$this->isBufferOpen = false;
		print strlen($resp) . ';' . $resp;
		flush();
	}
}
