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
 * \file    htdocs/custom/clinicpay/tests/integration/bill_step.php
 * \ingroup clinicpay
 * \brief   One-shot CLI runner for phase-2 integration tests. Each process
 *          bootstraps Dolibarr independently, so N parallel processes are a
 *          real concurrency test (acceptance B: 20 confirms -> 1 invoice).
 *
 * Usage (run with the DoliWamp PHP 7.4 binary):
 *   php bill_step.php create <fk_patient> <fk_product> <qty> [login]
 *   php bill_step.php confirm <bill_id> <channel> <channel_ref> [login]
 *   php bill_step.php refund_draft <bill_id> <reason> [login]
 *   php bill_step.php refund_exec <bill_id> [login]
 * Output: one JSON line {"rc":..,"id":..,"ref":..,"invoice":..,"error":..}
 */

define('DOL_DOCUMENT_ROOT', 'D:/dolibarr/www/dolibarr/htdocs');
$_SERVER['SCRIPT_FILENAME'] = __FILE__;
require DOL_DOCUMENT_ROOT.'/master.inc.php';

dol_include_once('/clinicpay/lib/clinicpay.lib.php');
dol_include_once('/clinicpay/class/paybill.class.php');

$out = array('rc' => 0, 'id' => 0, 'ref' => '', 'invoice' => 0, 'error' => '');
try {
	$op = isset($argv[1]) ? $argv[1] : '';
	// Fixed positions; the optional trailing argument is the acting login.
	$positions = array('create' => 4, 'confirm' => 4, 'refund_draft' => 3, 'refund_exec' => 2);
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
	$dao = new Paybill($db);

	if ($op === 'create') {
		$data = array(
			'fk_patient' => (int) $argv[2],
			'note' => 'integration test',
			'lines' => array(array('fk_product' => (int) $argv[3], 'qty' => (float) $argv[4])),
		);
		$out['rc'] = $dao->create($user, $data);
		$out['id'] = (int) $dao->id;
		$out['ref'] = $out['rc'] > 0 ? $dao->ref : '';
	} elseif ($op === 'confirm') {
		$dao->id = (int) $argv[2];
		$out['rc'] = $dao->confirm($user, $argv[3], $argv[4]);
		$out['invoice'] = (int) $dao->fk_invoice;
	} elseif ($op === 'refund_draft') {
		$dao->id = (int) $argv[2];
		$out['rc'] = $dao->createRefundDraft($user, $argv[3]);
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
