<?php
/**
 * PHPUnit bootstrap.
 *
 * For now this only needs the Composer autoloader: the type coverage report is
 * produced by static analysis of the source files and never executes them, so it
 * runs without WordPress. The code coverage report and the tests themselves will
 * need WordPress loaded, which is the next piece of work.
 *
 * @package AdminSuite
 */

declare( strict_types=1 );

require_once __DIR__ . '/../vendor/autoload.php';
