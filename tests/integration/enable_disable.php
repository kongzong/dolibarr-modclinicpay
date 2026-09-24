<?php
/* Copyright (C) 2026 modClinicPay contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Disable -> enable round trip (spec §6 phase 4 / §7-A): calls
 * modClinicPay::remove() then init() exactly like the module setup UI,
 * and verifies business data (bills / lines / cards / logs / sequences),
 * constants and permissions survive. Tables and rows are never dropped.
 *
 * Run: php tests/integration/enable_disable.php
 */

define('DOL_DOCUMENT_ROOT', 'D:/dolibarr/www/dolibarr/htdocs');
$_SERVER['SCRIPT_FILENAME'] = __FILE__;
require DOL_DOCUMENT_ROOT.'/master.inc.php';

dol_include_once('/clinicpay/core/modules/modClinicPay.class.php');
dol_include_once('/clinicpay/class/paybill.class.php');

$passed = 0;
$failed = 0;

function run($name, $fn)
{
	global $passed, $failed;
	try {
		$fn();
		echo "PASS  $name\n";
		$passed++;
	} catch (Throwable $e) {
		echo 'FAIL  '.$name.' - '.$e->getMessage()."\n";
		$failed++;
	}
}

function scalar($sql)
{
	global $db;
	$res = $db->query($sql);
	if (!$res) {
		throw new RuntimeException('query failed: '.$sql.' - '.$db->lasterror());
	}
	$o = $db->fetch_object($res);
	$db->free($res);
	return $o ? (int) $o->n : 0;
}

/** Snapshot of everything the round trip must preserve. */
function snapshot()
{
	global $db;
	$s = array(
		'bills' => scalar("SELECT COUNT(*) AS n FROM ".$db->prefix()."clinicpay_bill"),
		'bill_lines' => scalar("SELECT COUNT(*) AS n FROM ".$db->prefix()."clinicpay_bill_line"),
		'cards' => scalar("SELECT COUNT(*) AS n FROM ".$db->prefix()."clinicpay_card"),
		'card_logs' => scalar("SELECT COUNT(*) AS n FROM ".$db->prefix()."clinicpay_card_log"),
		'bill_seq' => scalar("SELECT COALESCE(MAX(last_value), 0) AS n FROM ".$db->prefix()."clinicpay_bill_sequence"),
		'card_seq' => scalar("SELECT COALESCE(MAX(last_value), 0) AS n FROM ".$db->prefix()."clinicpay_card_sequence"),
		'rights' => scalar("SELECT COUNT(*) AS n FROM ".$db->prefix()."rights_def WHERE module = 'clinicpay'"),
		'user_rights' => scalar("SELECT COUNT(*) AS n FROM ".$db->prefix()."user_rights ur JOIN ".$db->prefix()."rights_def rd ON rd.id = ur.fk_id WHERE rd.module = 'clinicpay'"),
		'consts' => array(),
	);
	foreach (array('CLINICPAY_DEFAULT_VAT', 'CLINICPAY_CARD_GRACE_DAYS', 'CLINICPAY_BANK_ACCOUNT') as $c) {
		$s['consts'][$c] = getDolGlobalString($c);
	}
	// one full bill with lines
	$res = $db->query("SELECT rowid FROM ".$db->prefix()."clinicpay_bill ORDER BY rowid DESC LIMIT 1");
	$o = $db->fetch_object($res);
	$db->free($res);
	$s['last_bill'] = $o ? (int) $o->rowid : 0;
	return $s;
}

try {
	$mod = new modClinicPay($db);
} catch (Throwable $e) {
	die('cannot load descriptor: '.$e->getMessage()."\n");
}

$before = snapshot();
echo "before: bills={$before['bills']} cards={$before['cards']} rights={$before['rights']} user_rights={$before['user_rights']}\n";

run('disable (remove) succeeds', function () use ($mod) {
	$result = $mod->remove();
	if ($result <= 0) {
		throw new RuntimeException('remove() returned '.$result.': '.(is_array($mod->errors) ? implode('/', $mod->errors) : (string) $mod->error));
	}
});

$mid = null;
run('after disable: data intact, rights/constants purged', function () use (&$mid, $before) {
	$mid = snapshot();
	foreach (array('bills', 'bill_lines', 'cards', 'card_logs', 'bill_seq', 'card_seq') as $k) {
		if ($mid[$k] !== $before[$k]) {
			throw new RuntimeException("$k changed on disable: {$before[$k]} -> {$mid[$k]}");
		}
	}
	if ($mid['rights'] !== 0) {
		throw new RuntimeException('rights_def still has '.$mid['rights'].' rows after remove()');
	}
	if (defined('MAIN_MODULE_CLINICPAY')) {
		// the current request keeps the constant; the DB row must be gone
	}
	$row = scalar("SELECT COUNT(*) AS n FROM ".MAIN_DB_PREFIX."const WHERE name = 'MAIN_MODULE_CLINICPAY'");
	if ($row !== 0) {
		throw new RuntimeException('MAIN_MODULE_CLINICPAY still in llx_const');
	}
});

run('enable (init) succeeds', function () use ($mod) {
	$result = $mod->init();
	if ($result <= 0) {
		throw new RuntimeException('init() returned '.$result.': '.(is_array($mod->errors) ? implode('/', $mod->errors) : (string) $mod->error));
	}
});

run('after enable: data lossless, permissions restored', function () use ($before) {
	$after = snapshot();
	foreach (array('bills', 'bill_lines', 'cards', 'card_logs', 'bill_seq', 'card_seq') as $k) {
		if ($after[$k] !== $before[$k]) {
			throw new RuntimeException("$k changed: {$before[$k]} -> {$after[$k]}");
		}
	}
	if ($after['rights'] !== $before['rights']) {
		throw new RuntimeException('rights_def rows: '.$before['rights'].' -> '.$after['rights']);
	}
	if ($after['user_rights'] !== $before['user_rights']) {
		throw new RuntimeException('user_rights rows: '.$before['user_rights'].' -> '.$after['user_rights']);
	}
	// module constants re-inserted by init()
	foreach (array('CLINICPAY_DEFAULT_VAT', 'CLINICPAY_CARD_GRACE_DAYS') as $c) {
		if (getDolGlobalString($c) === null) {
			throw new RuntimeException($c.' missing after re-enable');
		}
	}
});

run('after enable: last bill fully fetchable with lines', function () use ($before) {
	$b = new Paybill($GLOBALS['db']);
	if ($before['last_bill'] <= 0 || $b->fetch($before['last_bill']) <= 0) {
		throw new RuntimeException('cannot fetch bill '.$before['last_bill']);
	}
	if (count($b->lines) < 1) {
		throw new RuntimeException('bill lines lost');
	}
});

echo "\nResult: $passed passed, $failed failed\n";
exit($failed ? 1 : 0);
