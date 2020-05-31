<?php

namespace ssigwart\ProcessPool;

/** Process pool */
class ProcessPool
{
	/** Min number of processes */
	private $minNumProcs = 0;

	/** Max number of processes */
	private $maxNumProcs = 0;

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
		$this->cmd = $cmd;
		$this->cwd = $cwd;
		$this->env = $env;

		// Add processes
		for ($i = 0; $i < $this->minNumProcs; $i++)
			$this->addProcess();
	}

	/**
	 * Add a process
	 */
	private function addProcess()
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
				throw new ProcessPoolPoolExhaustedException();
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
	public function releaseProcess(ProcessPoolRequest $process)
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
			throw new ProcessPoolInvalidProcessException();
		$process->freeRequest();
		array_splice($this->runningProcs, $procIdx, 1);

		// Start a new process on failure
		if ($process->hasFailed())
			$this->addProcess();
		// Add this process back to the pull
		else
			$this->unassignedProcs[] = $process;
	}
}
