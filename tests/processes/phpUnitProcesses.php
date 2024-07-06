<?php

require(__DIR__ . '/../phpUnitConfig.php');

use TestAuxFiles\PhpUnitTestProcess;
use ssigwart\ProcessPool\ProcessPoolProcess;

try {
	$proc = new ProcessPoolProcess(new PhpUnitTestProcess());
	$proc->handleMessages();
} catch (Throwable $e) {
	error_log('Caught Exception: ' . get_class($e) . ': ' . $e->getCode() . ' - ' . $e->getMessage());
	exit(1);
}
