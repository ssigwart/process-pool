<?php

namespace ssigwart\ProcessPool;

/** Process pool process message handler interface */
interface ProcessPoolProcessMessageHandlerInterface
{
	/**
	 * Handle exit request
	 */
	public function handleExit();

	/**
	 * Handle request
	 *
	 * @param string $data Data
	 */
	public function handleRequest(string $data);
}
