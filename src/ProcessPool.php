<?php

namespace ssigwart\ProcessPool;

use Throwable;

/** Process pool */
class ProcessPool
{
	/** Min number of processes */
	private $minNumProcs = 0;

	/** Max number of processes */
	private $maxNumProcs = 0;

	/** Max number of unassigned processes */
	private $maxNumUnassignedProcs = 0;

	/** @var ProcessPoolRequest[] Running process pool */
	private $runningProcs = [];

	/** @var ProcessPoolRequest[] Unassigned process pool */
	private $unassignedProcs = [];

	/** @var string Command to run */
	private $cmd = null;

	/** @param string|null Working directory */
	private $cwd = null;

	/** @param array|null Hash of environment variable to value */
	private $env = null;

	/**
	 * Constructor
	 *
	 * @param int $minNumProcs Min number of processes
	 * @param int $maxNumProcs Max number of processes
	 * @param string $cmd Command to run
	 * @param string|null $cwd Working directory
	 * @param array|null $env Hash of environment variable to value
	 */
	public function __construct(int $minNumProcs, int $maxNumProcs, string $cmd, ?string $cwd = null, ?array $env = null)
	{
		$this->minNumProcs = $minNumProcs;
		$this->maxNumProcs = $maxNumProcs;
		$this->maxNumUnassignedProcs = min($this->minNumProcs + 5, $this->maxNumProcs);
		$this->cmd = $cmd;
		$this->cwd = $cwd;
		$this->env = $env;

		// Add processes
		for ($i = 0; $i < $this->minNumProcs; $i++)
			$this->addProcess();

		// Close processes on shutdown
		register_shutdown_function(function() {
			$this->handlePoolShutdown();
		});
	}

	/**
	 * Destructor
	 */
	public function __destruct()
	{
		// Close processes
		$this->handlePoolShutdown();
	}

	/**
	 * Handle pool shutdown
	 */
	private function handlePoolShutdown(): void
	{
		foreach ($this->runningProcs as $proc)
		{
			try {
				if (!$proc->hasFailed())
					$proc->sendExitRequest();
			} catch (Throwable $e) {
			}
			try {
				$proc->close();
			} catch (Throwable $e) {
			}
		}
		$this->runningProcs = [];

		foreach ($this->unassignedProcs as $proc)
		{
			try {
				if (!$proc->hasFailed())
					$proc->sendExitRequest();
			} catch (Throwable $e) {
			}
			try {
				$proc->close();
			} catch (Throwable $e) {
			}
		}
		$this->unassignedProcs = [];
	}

	/**
	 * Shut down process pool
	 */
	public function shutDown(): void
	{
		$this->handlePoolShutdown();
	}

	/**
	 * Set max number of space processes
	 *
	 * @param int $maxNumUnassignedProcs Max number of spare processes. Must be at least min number of processes
	 *
	 * @throws ProcessPoolException
	 */
	public function setMaxNumSpareProcesses(int $maxNumUnassignedProcs): void
	{
		if ($maxNumUnassignedProcs < $this->minNumProcs)
			throw new ProcessPoolException('Number of spare servers cannot be less than minimum number of processes (' . $this->minNumProcs . ').');
		$this->maxNumUnassignedProcs = $maxNumUnassignedProcs;
	}

	/**
	 * Get number of processes running
	 *
	 * @return int Number of processes
	 */
	public function getNumRunningProcesses(): int
	{
		return count($this->runningProcs);
	}

	/**
	 * Get number of processes started, but not service a request
	 *
	 * @return int Number of processes
	 */
	public function getNumUnassignedProcesses(): int
	{
		return count($this->unassignedProcs);
	}

	/**
	 * Add a process
	 */
	private function addProcess(): void
	{
		$this->unassignedProcs[] = new ProcessPoolRequest($this->cmd, $this->cwd, $this->env);
	}

	/**
	 * Start a process
	 *
	 * @return ProcessPoolRequest
	 */
	public function startProcess(): ProcessPoolRequest
	{
		// Check for unassigned process
		if (empty($this->unassignedProcs))
		{
			// Add processes
			if (count($this->runningProcs) < $this->maxNumProcs)
				$this->addProcess();
			// Full
			else
				throw new ProcessPoolPoolExhaustedException('Max number of processes (' . $this->maxNumProcs . ') reached.');
		}

		$proc = array_pop($this->unassignedProcs);
		$this->runningProcs[] = $proc;

		return $proc;
	}

	/**
	 * Release a process
	 *
	 * @param ProcessPoolRequest $process Process
	 * @throws ProcessPoolException
	 */
	public function releaseProcess(ProcessPoolRequest $process): void
	{
		// Find process
		$procIdx = null;
		foreach ($this->runningProcs as $idx=>$runningProc)
		{
			if ($runningProc === $process)
			{
				$procIdx = $idx;
				break;
			}
		}
		if ($procIdx === null)
			throw new ProcessPoolInvalidProcessException('Process not found.');
		$process->freeRequest();
		array_splice($this->runningProcs, $procIdx, 1);

		// Start a new process on failure
		if ($process->hasFailed())
			$this->addProcess();
		// Add this process back to the pool if needed
		else if ($this->getNumUnassignedProcesses() + 1 <= $this->maxNumUnassignedProcs)
		{
			if ($process->hasStdoutData())
				$process->getStdoutResponse();
			if ($process->hasStderrData())
				$process->getStderrResponse();
			$this->unassignedProcs[] = $process;
		}
		// Otherwise, tell it to exit
		else
		{
			try {
				$process->sendExitRequest();
			} catch (Throwable $e) {
				// Suppress error
			}
			try {
				$process->close();
			} catch (Throwable $e) {
				// Suppress error
			}
		}
	}
}
