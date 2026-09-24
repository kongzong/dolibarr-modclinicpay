<?php
/* Copyright (C) 2026 modClinicPay contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * REST API integration test (spec §3.8 / §7-F). Bootstraps Dolibarr in CLI,
 * injects the acting user into DolibarrApiAccess::$user (what the Restler
 * auth layer does on a real request) and calls the Clinicpay API class
 * directly: happy paths, idempotent confirm, the 403 permission matrix and
 * the 409 refusal paths (invalid channel, used-up card, same-person refund).
 *
 * Run: php tests/integration/api.php
 */

define('DOL_DOCUMENT_ROOT', 'D:/dolibarr/www/dolibarr/htdocs');
$_SERVER['SCRIPT_FILENAME'] = __FILE__;
require DOL_DOCUMENT_ROOT.'/master.inc.php';

use Luracast\Restler\RestException;

require_once DOL_DOCUMENT_ROOT.'/api/class/api_access.class.php';
require_once DOL_DOCUMENT_ROOT.'/api/class/api.class.php';
dol_include_once('/clinicpay/class/api_clinicpay.class.php');

$passed = 0;
$failed = 0;

/**
 * @param	string	$name	Test name
 * @param	callable	$fn	Throws on failure
 * @return	void
 */
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

/**
 * @param	int		$code	Expected HTTP status
 * @param	callable	$fn	Callable expected to throw RestException
 * @return	void
 */
function expectRest($code, $fn)
{
	try {
		$fn();
		throw new RuntimeException('expected RestException '.$code.', nothing thrown');
	} catch (RestException $e) {
		if ((int) $e->getCode() !== $code) {
			throw new RuntimeException('expected RestException '.$code.', got '.$e->getCode().': '.$e->getMessage());
		}
	}
}

/**
 * Acting user: real login, rights optionally overridden to a minimal set.
 *
 * @param	string	$login	Login
 * @param	array|null	$rights	null = real rights; array of feature => 1 (read/write/pay/validate/consume/admin)
 * @return	User
 */
function actor($login, array $rights = null)
{
	global $db;
	static $cache = array();
	if (!isset($cache[$login])) {
		$u = new User($db);
		if ($u->fetch('', $login) <= 0) {
			throw new RuntimeException('user not found: '.$login);
		}
		$u->loadRights();
		$cache[$login] = $u;
	}
	$u = $cache[$login];
	if ($rights !== null) {
		$u->rights->clinicpay = (object) $rights;
	}
	DolibarrApiAccess::$user = $u;
	return $u;
}

try {
	$api = new Clinicpay();
} catch (Throwable $e) {
	die('cannot instantiate API: '.$e->getMessage()."\n");
}

// Fixed fixtures from the earlier phases
$fkPatient = 0;
$res = $db->query("SELECT rowid FROM ".$db->prefix()."patient_profile ORDER BY rowid ASC");
if ($res && ($o = $db->fetch_object($res))) {
	$fkPatient = (int) $o->rowid;
}
$db->free($res);
if ($fkPatient <= 0) {
	die("no patient_profile row found\n");
}
$fkProduct = 0;
$res = $db->query("SELECT rowid FROM ".$db->prefix()."product WHERE ref = 'CPTEST-1'");
if ($res && ($o = $db->fetch_object($res))) {
	$fkProduct = (int) $o->rowid;
}
$db->free($res);
if ($fkProduct <= 0) {
	die("CPTEST-1 product not found\n");
}

// ------------------------------------------------------------ happy paths (admin)

run('bills index (read)', function () use ($api) {
	actor('admin');
	$res = $api->indexBills('', 0, -1, '', '', 10, 0);
	if (!isset($res['total']) || $res['total'] < 1 || count($res['rows']) < 1) {
		throw new RuntimeException('expected non-empty bill list');
	}
});

$billId = 0;
run('post bill (write) -> draft with snapshot line', function () use ($api, $fkPatient, $fkProduct, &$billId) {
	actor('admin');
	$res = $api->postBill(array('fk_patient' => $fkPatient, 'note' => 'api test',
		'lines' => array(array('fk_product' => $fkProduct, 'qty' => 2))));
	if ((int) $res['status'] !== 0 || $res['fk_invoice'] !== null || count($res['lines']) !== 1) {
		throw new RuntimeException('unexpected bill shape: '.json_encode($res));
	}
	if (strpos((string) $res['ref'], 'SF-') !== 0) {
		throw new RuntimeException('unexpected ref: '.$res['ref']);
	}
	$billId = (int) $res['id'];
});

run('confirm bill (pay, SCAN+ref) -> paid with invoice', function () use ($api, $billId) {
	actor('admin');
	$res = $api->confirmBill($billId, array('channel' => 'SCAN', 'channel_ref' => 'API-TEST-0001'));
	if ((int) $res['status'] !== 1 || (int) $res['fk_invoice'] <= 0) {
		throw new RuntimeException('unexpected state after confirm');
	}
});

run('confirm idempotent (second call, no second invoice)', function () use ($api, $billId) {
	actor('admin');
	$before = $api->getBill($billId);
	$api->confirmBill($billId, array('channel' => 'CASH'));
	$after = $api->getBill($billId);
	if ((int) $after['fk_invoice'] !== (int) $before['fk_invoice']) {
		throw new RuntimeException('invoice changed on re-confirm');
	}
});

run('get bill (read, with lines + patient name, no id document)', function () use ($api, $billId) {
	actor('admin');
	$res = $api->getBill($billId);
	if (count($res['lines']) !== 1 || $res['patient_name'] === null) {
		throw new RuntimeException('unexpected getBill shape');
	}
	foreach (array('idcard', 'id_card', 'cert', '证件') as $forbidden) {
		if (array_key_exists($forbidden, $res)) {
			throw new RuntimeException('identity document field exposed: '.$forbidden);
		}
	}
});

run('cards index (read)', function () use ($api) {
	actor('admin');
	$res = $api->indexCards('', 0, -1, '', 10, 0);
	if (!isset($res['total'])) {
		throw new RuntimeException('unexpected card list shape');
	}
});

$cardId = 0;
run('post card (write+pay, COUNT 2) -> valid card + CREATE log', function () use ($api, $fkPatient, $fkProduct, &$cardId) {
	actor('admin');
	$res = $api->postCard(array('fk_patient' => $fkPatient, 'card_type' => 'COUNT', 'count' => 2,
		'fk_product' => $fkProduct, 'channel' => 'CASH'));
	if ((int) $res['status'] !== 0 || $res['card_type'] !== 'COUNT' || (int) $res['total_count'] !== 2) {
		throw new RuntimeException('unexpected card shape: '.json_encode($res));
	}
	if (strpos((string) $res['ref'], 'CK-') !== 0 || count($res['logs']) < 1) {
		throw new RuntimeException('unexpected card ref/logs');
	}
	$cardId = (int) $res['id'];
});

run('consume card (consume) -> used_count 1', function () use ($api, $cardId) {
	actor('admin');
	$res = $api->consumeCard($cardId, array('note' => 'api consume 1'));
	if ((int) $res['used_count'] !== 1) {
		throw new RuntimeException('expected used_count 1, got '.$res['used_count']);
	}
});

// ------------------------------------------------------------ 409 refusals

run('confirm with invalid channel -> 409', function () use ($api) {
	actor('admin');
	expectRest(409, function () use ($api) {
		$res = $api->postBill(array('fk_patient' => 1, 'lines' => array(array('label' => 'X', 'price_unit' => 1, 'qty' => 1))));
		$api->confirmBill((int) $res['id'], array('channel' => 'WECHAT'));
	});
});

run('consume last count twice -> 409 used up', function () use ($api, $cardId) {
	actor('admin');
	$api->consumeCard($cardId, array('note' => 'api consume 2')); // uses the last count
	expectRest(409, function () use ($api, $cardId) {
		$api->consumeCard($cardId, array('note' => 'api consume 3'));
	});
});

run('refund draft -> execute by same person (ray) -> 409 double person', function () use ($api, $billId) {
	// ray has no clinicpay rights in DB; grant write+validate. The draft
	// creator and the executor are the same real user (ray) -> refused.
	actor('ray', array('read' => 1, 'write' => 1, 'validate' => 1));
	$api->refundBill($billId, array('reason' => 'api two-person test'));
	expectRest(409, function () use ($api, $billId) {
		$api->refundBill($billId, array('reason' => 'api two-person test', 'execute' => true));
	});
});

run('refund execute by admin (different person) -> refunded', function () use ($api, $billId) {
	actor('admin');
	$res = $api->refundBill($billId, array('reason' => 'api two-person test', 'execute' => true));
	if ((int) $res['status'] !== 9) {
		throw new RuntimeException('expected status 9, got '.$res['status']);
	}
});

// ------------------------------------------------------------ 403 matrix (read-only user)

run('403 matrix: read-only user cannot write/pay/validate/consume', function () use ($api, $fkPatient) {
	actor('ray', array('read' => 1)); // strip everything but read
	expectRest(403, function () use ($api, $fkPatient) {
		$api->postBill(array('fk_patient' => $fkPatient, 'lines' => array(array('label' => 'X', 'price_unit' => 1, 'qty' => 1))));
	});
	expectRest(403, function () use ($api, $billId) {
		$api->confirmBill($billId, array('channel' => 'CASH'));
	});
	expectRest(403, function () use ($api, $billId) {
		$api->refundBill($billId, array('reason' => 'x'));
	});
	expectRest(403, function () use ($api, $fkPatient) {
		$api->postCard(array('fk_patient' => $fkPatient, 'card_type' => 'COUNT', 'count' => 1, 'channel' => 'CASH'));
	});
	expectRest(403, function () use ($api, $cardId) {
		$api->consumeCard($cardId, array());
	});
});

run('read-only user can still GET', function () use ($api, $billId, $cardId) {
	actor('ray', array('read' => 1));
	$res = $api->getBill($billId);
	if ((int) $res['id'] !== $billId) {
		throw new RuntimeException('getBill failed for read-only user');
	}
	$res = $api->getCard($cardId);
	if ((int) $res['id'] !== $cardId) {
		throw new RuntimeException('getCard failed for read-only user');
	}
});

run('restore ray real rights', function () {
	actor('ray'); // re-set without override
});

echo "\nResult: $passed passed, $failed failed\n";
exit($failed ? 1 : 0);
