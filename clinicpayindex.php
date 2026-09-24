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
 * \file    htdocs/custom/clinicpay/clinicpayindex.php
 * \ingroup clinicpay
 * \brief   Module landing page: links to the bill list and card list.
 */

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

require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
dol_include_once('/clinicpay/lib/clinicpay.lib.php');

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

$langs->loadLangs(array("clinicpay@clinicpay"));

if (!$user->hasRight('clinicpay', 'read')) {
	accessforbidden();
}

llxHeader('', $langs->trans("ModuleClinicPayName"));

print '<div class="fichecenter">';
print '<div class="underbanner clearboth"></div>';

print '<table class="border tableforfield centpercent">';
print '<tr><td class="titlefield">'.$langs->trans("ClinicPayBillList").'</td><td><a href="'.dol_buildpath('/clinicpay/bill_list.php', 1).'">'.dol_escape_htmltag($langs->trans("ClinicPayBillList")).'</a></td></tr>';
print '<tr><td>'.$langs->trans("ClinicPayCardList").'</td><td><a href="'.dol_buildpath('/clinicpay/card_list.php', 1).'">'.dol_escape_htmltag($langs->trans("ClinicPayCardList")).'</a></td></tr>';
if ($user->hasRight('clinicpay', 'admin')) {
	print '<tr><td>'.$langs->trans("ClinicPaySetup").'</td><td><a href="'.dol_buildpath('/clinicpay/admin/setup.php', 1).'">'.dol_escape_htmltag($langs->trans("ClinicPaySetup")).'</a></td></tr>';
}
print '</table>';
print '</div>';

llxFooter();
$db->close();
