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
 * \file    htdocs/custom/clinicpay/ajax/bundle.php
 * \brief   Read-only JSON for the charge form's "apply bundle" picker.
 *          GET ?id=N -> bundle head + rows [{fk_product,qty}]
 *          Requires 'clinicpay read'.
 */

if (!defined('NOTOKENRENEWAL')) {
	define('NOTOKENRENEWAL', '1');
}
if (!defined('NOREQUIREMENU')) {
	define('NOREQUIREMENU', '1');
}
if (!defined('NOREQUIREHTML')) {
	define('NOREQUIREHTML', '1');
}
if (!defined('NOREQUIREAJAX')) {
	define('NOREQUIREAJAX', '1');
}

$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

dol_include_once('/clinicpay/class/bundle.class.php');

/* @var DoliDB $db */

header('Content-Type: application/json; charset=utf-8');

if (!$user->hasRight('clinicpay', 'read')) {
	print json_encode(array('error' => 'forbidden'));
	exit;
}

$id = GETPOSTINT('id');

if ($id > 0) {
	$o = new ClinicPayBundle($db);
	if ($o->fetch($id) <= 0 || !$o->status) {
		print json_encode(array('error' => 'notfound'));
		exit;
	}
	$lines = array();
	foreach ($o->lines as $l) {
		$lines[] = array(
			'fk_product' => (int) $l['fk_product'],
			'qty' => rtrim(rtrim(number_format((float) $l['qty'], 3, '.', ''), '0'), '.'),
		);
	}
	print json_encode(array(
		'id' => $o->rowid,
		'name' => $o->name,
		'lines' => $lines,
	), JSON_UNESCAPED_UNICODE);
	exit;
}

print json_encode(array('error' => 'badrequest'));
