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
 * \file    htdocs/custom/clinicpay/admin/setup.php
 * \ingroup clinicpay
 * \brief   Module settings: default VAT and card expiry grace days.
 */

$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res && file_exists("../../../../main.inc.php")) {
	$res = @include "../../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
dol_include_once('/clinicpay/core/modules/modClinicPay.class.php');
dol_include_once('/clinicpay/class/paybillnumbering.class.php');

/**
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

$langs->loadLangs(array("admin", "clinicpay@clinicpay"));

if (!$user->admin && !$user->hasRight('clinicpay', 'admin')) {
	accessforbidden();
}

$module = new modClinicPay($db);

// Save
if (GETPOST('action', 'aZ09') === 'update' && $user->admin) {
	$defaultVat = GETPOST('CLINICPAY_DEFAULT_VAT', 'alpha');
	$graceDays = GETPOST('CLINICPAY_CARD_GRACE_DAYS', 'int');
	if (!is_numeric($defaultVat)) {
		$defaultVat = '0';
	}
	if (!is_numeric($graceDays)) {
		$graceDays = 0;
	}
	dolibarr_set_const($db, 'CLINICPAY_DEFAULT_VAT', $defaultVat, 'chaine', 0, '', $conf->entity);
	dolibarr_set_const($db, 'CLINICPAY_CARD_GRACE_DAYS', (string) $graceDays, 'chaine', 0, '', $conf->entity);
	$bankAccount = (int) GETPOST('CLINICPAY_BANK_ACCOUNT', 'int');
	dolibarr_set_const($db, 'CLINICPAY_BANK_ACCOUNT', (string) $bankAccount, 'chaine', 0, '', $conf->entity);
	setEventMessages($langs->trans("SetupSaved"), null, 'mesgs');
	header("Location: ".$_SERVER["PHP_SELF"]);
	exit;
}

llxHeader('', $langs->trans("ClinicPaySetup"));

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans("BackToModuleList").'</a>';
print load_fiche_titre($langs->trans("ClinicPaySetup"), $linkback, 'title_setup');

$head = array();
$head[0][0] = $_SERVER["PHP_SELF"];
$head[0][1] = $langs->trans("Settings");
$head[0][2] = 'settings';
print dol_get_fiche_head($head, 'settings', $langs->trans("ModuleClinicPayName"), -1, 'fa-credit-card');

print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="update">';

print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans("Parameter").'</td><td>'.$langs->trans("Value").'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans("ClinicPayDefaultVat").'</td>';
print '<td><input type="text" name="CLINICPAY_DEFAULT_VAT" value="'.dol_escape_htmltag(getDolGlobalString('CLINICPAY_DEFAULT_VAT', '0')).'" class="maxwidth50"></td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans("ClinicPayCardGraceDays").'</td>';
print '<td><input type="text" name="CLINICPAY_CARD_GRACE_DAYS" value="'.dol_escape_htmltag(getDolGlobalString('CLINICPAY_CARD_GRACE_DAYS', '0')).'" class="maxwidth50"></td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans("ClinicPayBankAccount").'<br><span class="opacitymedium">'.$langs->trans("ClinicPayBankAccountHint").'</span></td>';
print '<td><input type="text" name="CLINICPAY_BANK_ACCOUNT" value="'.dol_escape_htmltag(getDolGlobalString('CLINICPAY_BANK_ACCOUNT', '0')).'" class="maxwidth75"></td></tr>';
print '</table>';

// Numbering preview (a call outside a transaction is read-only)
try {
	$numbering = new PaybillNumbering($db);
	$preview = $numbering->nextReference(PaybillNumbering::prefixFor());
} catch (Throwable $e) {
	$preview = $e->getMessage();
}
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans("ClinicPayNumberingPreview").'</td></tr>';
print '<tr class="oddeven"><td><span class="badge badge-status4">'.dol_escape_htmltag((string) $preview).'</span> <span class="opacitymedium">SF-YYYYMMDD-NNN</span></td></tr>';
print '</table>';

print '<div class="center"><input type="submit" class="button" value="'.$langs->trans("Save").'"></div>';
print '</form>';

print dol_get_fiche_end();

llxFooter();
$db->close();
