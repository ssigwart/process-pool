<?php

namespace ssigwart\ProcessPool;

/** Process pool exception that is thrown if a newly started process already has STDOUT or STDERR output */
class ProcessPoolProcessOutputBeforeStartingException extends ProcessPoolException
{
	/** @var string[] STDERR lines */
	private array $stderrLines = [];

	/** @var string[] STDOUT lines */
	private array $stdoutLines = [];

	/**
	 * Constructor
	 *
	 * @param string[] $stderrLines STDERR lines
	 * @param string[] $stdoutLines STDOUT lines
	 */
	public function __construct(array $stderrLines, array $stdoutLines)
	{
		$this->stderrLines = $stderrLines;
		$this->stdoutLines = $stdoutLines;
	}

	/**
	 * Get STDERR lines
	 *
	 * @return string[] STDERR lines
	 */
	public function getStderrLines(): array
	{
		return $this->stderrLines;
	}

	/**
	 * Get STDOUT lines
	 *
	 * @return string[] STDOUT lines
	 */
	public function getStdoutLines(): array
	{
		return $this->stdoutLines;
	}
}
