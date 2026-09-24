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
 * \file    htdocs/custom/clinicpay/patient_tab.php
 * \ingroup clinicpay
 * \brief   Patient card tab: charges and prepaid cards of this patient.
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

dol_include_once('/patient/class/patientprofile.class.php');
dol_include_once('/patient/lib/patient.lib.php');
dol_include_once('/clinicpay/lib/clinicpay.lib.php');
dol_include_once('/clinicpay/class/paybill.class.php');
dol_include_once('/clinicpay/class/servicecard.class.php');

/**
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

$langs->loadLangs(array("patient@patient", "clinicpay@clinicpay"));

$tab = GETPOST('tab', 'aZ09');
$id = GETPOSTINT('id');
if ($id <= 0 || !in_array($tab, array('bills', 'cards')) || !$user->hasRight('patient', 'read') || !$user->hasRight('clinicpay', 'read')) {
	accessforbidden();
}

$patient = new PatientProfile($db);
if ($patient->fetch($id) <= 0) {
	accessforbidden($langs->trans("PatientNotYet"));
}

llxHeader('', $langs->trans("ClinicPayTab"));

$head = patient_prepare_head($patient);
print dol_get_fiche_head($head, ($tab === 'cards' ? 'clinicpay_cards' : 'clinicpay_bills'), $langs->trans("PatientTab"), -1, 'user');

print patient_summary_banner(patient_get_summary($db, $patient->id), array(), 'clinicpay');

if ($tab === 'bills') {
	if ($user->hasRight('clinicpay', 'write')) {
		$base = dol_buildpath('/clinicpay/bill.php', 1).'?action=create&fk_patient='.$patient->id;
		print '<div class="tabsAction">';
		print dolGetButtonAction($langs->trans("ClinicPayBillNew"), '', 'default', $base, '', 1);
		print '</div>';
	}

	$rows = clinicpay_bill_list_by_patient($db, $patient->id, 50);
	print '<div class="div-table-responsive">';
	print '<table class="tagtable liste centpercent">'."\n";
	print '<tr class="liste_titre"><th>'.$langs->trans("ClinicPayRef").'</th><th class="right">'.$langs->trans("ClinicPayBillAmount").'</th><th class="center">'.$langs->trans("ClinicPayBillDate").'</th><th class="center">'.$langs->trans("Status").'</th></tr>';
	if (empty($rows)) {
		print '<tr><td colspan="4"><span class="opacitymedium">'.$langs->trans("ClinicPayNoBill").'</span></td></tr>';
	}
	foreach ($rows as $r) {
		print '<tr class="oddeven">';
		print '<td><a href="'.dol_buildpath('/clinicpay/bill.php', 1).'?id='.(int) $r->rowid.'">'.dol_escape_htmltag($r->ref).'</a></td>';
		print '<td class="right">'.price($r->amount_total).'</td>';
		print '<td class="center">'.dol_print_date($db->jdate($r->date_creation), 'dayhour').'</td>';
		print '<td class="center">'.clinicpay_bill_status_badge($r->status).'</td>';
		print '</tr>';
	}
	print '</table></div>';
} else {
	if ($user->hasRight('clinicpay', 'write')) {
		$base = dol_buildpath('/clinicpay/card.php', 1).'?action=create&fk_patient='.$patient->id;
		print '<div class="tabsAction">';
		print dolGetButtonAction($langs->trans("ClinicPayCardNew"), '', 'default', $base, '', 1);
		print '</div>';
	}

	$rows = clinicpay_card_list_by_patient($db, $patient->id, 50);
	print '<div class="div-table-responsive">';
	print '<table class="tagtable liste centpercent">'."\n";
	print '<tr class="liste_titre"><th>'.$langs->trans("ClinicPayCardNo").'</th><th>'.$langs->trans("ClinicPayCardType").'</th><th class="center">'.$langs->trans("ClinicPayCardDateEnd").'</th><th class="center">'.$langs->trans("ClinicPayCardStatus").'</th></tr>';
	if (empty($rows)) {
		print '<tr><td colspan="4"><span class="opacitymedium">'.$langs->trans("ClinicPayNoCard").'</span></td></tr>';
	}
	foreach ($rows as $r) {
		print '<tr class="oddeven">';
		print '<td><a href="'.dol_buildpath('/clinicpay/card.php', 1).'?id='.(int) $r->rowid.'">'.dol_escape_htmltag($r->ref).'</a></td>';
		print '<td>'.clinicpay_card_type_label($r->card_type).'</td>';
		print '<td class="center">'.($r->date_end ? dol_print_date($db->jdate($r->date_end), 'day') : '').'</td>';
		print '<td class="center">'.clinicpay_card_status_badge($r->status).'</td>';
		print '</tr>';
	}
	print '</table></div>';
}

print dol_get_fiche_end();

llxFooter();
$db->close();
