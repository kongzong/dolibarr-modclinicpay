<?php
/* Copyright (C) 2026  modClinicPay contributors
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    htdocs/custom/clinicpay/tests/integration/card_step.php
 * \ingroup clinicpay
 * \brief   One-shot CLI runner for phase-3 integration tests (sell / consume
 *          / card refund). Each process bootstraps Dolibarr independently,
 *          so N parallel processes are a real concurrency test (acceptance
 *          B: 20 consumes of the last visit -> exactly one success).
 *
 * Usage (run with the DoliWamp PHP 7.4 binary):
 *   php card_step.php sell <fk_patient> <COUNT|VALUE> <count> <value> <channel> <channel_ref> [login]
 *   php card_step.php consume <card_id> [amount] [login]
 *   php card_step.php refund_create <card_id> <reason> [login]
 *   php card_step.php refund_exec <card_id> [login]
 * Output: one JSON line {"rc":..,"id":..,"ref":..,"bill":..,"error":..}
 */

define('DOL_DOCUMENT_ROOT', 'D:/dolibarr/www/dolibarr/htdocs');
$_SERVER['SCRIPT_FILENAME'] = __FILE__;
require DOL_DOCUMENT_ROOT.'/master.inc.php';

dol_include_once('/clinicpay/lib/clinicpay.lib.php');
dol_include_once('/clinicpay/class/servicecard.class.php');

$out = array('rc' => 0, 'id' => 0, 'ref' => '', 'bill' => 0, 'error' => '');
try {
	$op = isset($argv[1]) ? $argv[1] : '';
	// Fixed positions; the optional trailing argument is the acting login.
	$positions = array('sell' => 8, 'charge' => 8, 'consume' => 4, 'refund_create' => 3, 'refund_exec' => 2);
	if (!isset($positions[$op])) {
		throw new RuntimeException('unknown op: '.$op);
	}
	$login = 'admin';
	if ($argc > $positions[$op] + 1) {
		$login = $argv[$positions[$op] + 1];
	}
	$user = new User($db);
	if ($user->fetch('', $login) <= 0) {
		throw new RuntimeException('user not found: '.$login);
	}
	$user->loadRights(); // CLI has no web session: rights are not auto-loaded
	$dao = new ServiceCard($db);

	if ($op === 'sell') {
		$data = array(
			'fk_patient' => (int) $argv[2],
			'card_type' => (string) $argv[3],
			'count' => (int) $argv[4],
			'value' => (float) $argv[5],
			'channel' => (string) $argv[6],
			'channel_ref' => (string) $argv[7],
			'note' => 'integration test',
		);
		$out['rc'] = $dao->sell($user, $data);
		$out['id'] = (int) $dao->id;
		$out['ref'] = $out['rc'] > 0 ? $dao->ref : '';
		$out['bill'] = $out['rc'] > 0 ? (int) $dao->bill_id : 0;
	} elseif ($op === 'charge') {
		$dao->id = (int) $argv[2];
		$data = array(
			'card_type' => (string) $argv[3],
			'count' => (int) $argv[4],
			'value' => (float) $argv[5],
			'channel' => (string) $argv[6],
			'channel_ref' => (string) $argv[7],
			'note' => 'integration test',
		);
		$out['rc'] = $dao->charge($user, $data);
		$out['bill'] = $out['rc'] > 0 ? (int) $dao->bill_id : 0;
	} elseif ($op === 'consume') {
		$dao->id = (int) $argv[2];
		$amount = isset($argv[3]) && $argv[3] !== '' ? (float) $argv[3] : 0.0;
		$out['rc'] = $dao->consume($user, $amount, 'integration test');
		$out['id'] = (int) $dao->id;
	} elseif ($op === 'refund_create') {
		$dao->id = (int) $argv[2];
		$out['rc'] = $dao->createRefund($user, (string) $argv[3]);
		$out['bill'] = $out['rc'] > 0 ? (int) $dao->bill_id : 0;
	} elseif ($op === 'refund_exec') {
		$dao->id = (int) $argv[2];
		$out['rc'] = $dao->executeRefund($user);
	}
	if ($out['rc'] < 0) {
		$out['error'] = $dao->error;
	}
} catch (Throwable $e) {
	$out['rc'] = -1;
	$out['error'] = $e->getMessage();
}
print json_encode($out)."\n";
