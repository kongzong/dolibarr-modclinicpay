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

$langs->loadLangs(array("patient@patient", "clinicpay@clinicpay", "medrecord@medrecord"));

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

// Patient header in the card/allergies fiche style (no summary banner here;
// the summary mode with quick buttons is for sub-data detail pages, design §5.1)
$linkback = '<a href="'.dol_buildpath('/patient/list.php', 1).'?restore_lastsearch_values=1">'.$langs->trans("BackToList").'</a>';
print '<div class="arearef heightref valignmiddle centpercent">';
print '<div class="inline-block floatleft refid refidpadding">'.img_picto('', 'user', 'class="pictofixedwidth"').'<strong>'.dol_escape_htmltag($patient->card_no).'</strong>';
print ($patient->thirdparty ? ' - '.dol_escape_htmltag($patient->thirdparty->name) : '').'</div>';
print '<div class="inline-block floatright">'.$linkback.'</div>';
print '<div class="clearboth"></div></div>';
print '<div class="underbanner clearboth"></div>';

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
	print '<tr class="liste_titre"><th>'.$langs->trans("ClinicPayRef").'</th><th class="right">'.$langs->trans("ClinicPayBillAmount").'</th><th class="center">'.$langs->trans("ClinicPayBillDate").'</th><th>'.$langs->trans("MedRecordBelonging").'</th><th>'.$langs->trans("ClinicPayPrescriptionLink").'</th><th class="center">'.$langs->trans("Status").'</th></tr>';
	if (empty($rows)) {
		print '<tr><td colspan="6"><span class="opacitymedium">'.$langs->trans("ClinicPayNoBill").'</span></td></tr>';
	}
	foreach ($rows as $r) {
		print '<tr class="oddeven">';
		print '<td><a href="'.dol_buildpath('/clinicpay/bill.php', 1).'?id='.(int) $r->rowid.'">'.dol_escape_htmltag($r->ref).'</a></td>';
		print '<td class="right">'.price($r->amount_total).'</td>';
		print '<td class="center">'.dol_print_date($db->jdate($r->date_creation), 'dayhour').'</td>';
		$medHtml = '<span class="opacitymedium">—</span>';
		if (!empty($r->fk_medrecord)) {
			$medLabel = ($r->medrecord_ref !== null && $r->medrecord_ref !== '') ? $r->medrecord_ref : '#'.(int) $r->fk_medrecord;
			$medHtml = '<a href="'.dol_buildpath('/medrecord/card.php', 1).'?id='.((int) $r->fk_medrecord).'">'.dol_escape_htmltag($medLabel).'</a>';
		}
		print '<td>'.$medHtml.'</td>';
		$prescHtml = '<span class="opacitymedium">—</span>';
		if (!empty($r->presc_pairs)) {
			$prescLinks = array();
			foreach (explode(',', $r->presc_pairs) as $pair) {
				$sep = strpos($pair, ':');
				if ($sep === false) {
					continue;
				}
				$prescId = (int) substr($pair, 0, $sep);
				$prescRef = substr($pair, $sep + 1);
				$prescLinks[] = '<a href="'.dol_buildpath('/prescription/card.php', 1).'?id='.$prescId.'">'.dol_escape_htmltag($prescRef).'</a>';
			}
			if ($prescLinks) {
				$prescHtml = implode(', ', $prescLinks);
			}
		}
		print '<td>'.$prescHtml.'</td>';
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
