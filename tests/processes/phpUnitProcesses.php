<?php

require(__DIR__ . '/../phpUnitConfig.php');

use TestAuxFiles\PhpUnitTestProcess;
use ssigwart\ProcessPool\ProcessPoolProcess;

$proc = new ProcessPoolProcess(new PhpUnitTestProcess());
$proc->handleMessages();
