<?php
/* Copyright (C) 2026  modClinicPay contributors
 *
 * Behaviour tests: these talk to the database instead of reading source text.
 *
 * The static suites under tests/unit assert what the code LOOKS LIKE; a wrong
 * variable name or a private method slipped through all of them today. These
 * tests call the real code and assert what it DOES, so only a behaviour change
 * can turn them red.
 *
 * Data policy (2026-10-04, see docs/behavior-test-data-source.md):
 *   - read-only tests reuse the demo documents and assert INVARIANTS, never
 *     row counts, so demo data may change without breaking them;
 *   - anything that writes builds its own rows and rolls back, so the demo
 *     database is never modified.
 *
 * Usage: php tests/run_db.php
 * Exits non-zero when a test fails. A test that cannot run (no database, no
 * demo document to read) reports SKIP rather than failing the suite.
 */

if (PHP_SAPI !== 'cli') {
	die('CLI only');
}

require_once dirname(dirname(__DIR__)).'/scripts/testlib/bootstrap_db.php';

use ClinicPay\Behavior\BehaviorTestCase;
use ClinicPay\Behavior\BehaviorTestSkip;

$suites = array(
	'PaybillSearchBehaviorTest.php',
	'StockCountPostBehaviorTest.php',
	'DispenseConfirmBehaviorTest.php',
	'ListPlaceholderBehaviorTest.php',
);

$pass = 0;
$fail = 0;
$skip = 0;
$failures = array();

foreach ($suites as $file) {
	$path = __DIR__.'/behavior/'.$file;
	if (!file_exists($path)) {
		continue;
	}
	require_once $path;
	$class = basename($file, '.php');
	if (!class_exists($class)) {
		print "FAIL  ".$class." (class not found)\n";
		$fail++;
		continue;
	}
	$ref = new ReflectionClass($class);
	foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
		if (strncmp($method->name, 'test', 4) !== 0) {
			continue;
		}
		$instance = $ref->newInstance();
		try {
			$instance->setUp();
			$method->invoke($instance);
			$instance->tearDown();
			print "PASS  ".$class."::".$method->name."\n";
			$pass++;
		} catch (BehaviorTestSkip $e) {
			$instance->tearDown();
			print "SKIP  ".$class."::".$method->name." - ".$e->getMessage()."\n";
			$skip++;
		} catch (Throwable $e) {
			// Always tear down: a failed test must not leave rows behind.
			$instance->tearDown();
			print "FAIL  ".$class."::".$method->name." - ".get_class($e).": ".$e->getMessage()."\n";
			$fail++;
			$failures[] = $class."::".$method->name;
		}
	}
}

print "\nResult: ".$pass." passed, ".$fail." failed";
if ($skip > 0) {
	print ", ".$skip." skipped";
}
print "\n";
if (!empty($failures)) {
	print "failing: ".implode(', ', $failures)."\n";
}
exit($fail > 0 ? 1 : 0);
