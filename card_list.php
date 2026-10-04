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
 * \file    htdocs/custom/clinicpay/card_list.php
 * \ingroup clinicpay
 * \brief   Prepaid card list: card no / type / name / status / valid until.
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
dol_include_once('/patient/lib/patient.lib.php');
dol_include_once('/clinicpay/lib/clinicpay.lib.php');
dol_include_once('/clinicpay/class/servicecard.class.php');

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

$langs->loadLangs(array("patient@patient", "clinicpay@clinicpay"));

if (!$user->hasRight('clinicpay', 'read')) {
	accessforbidden();
}

$form = new Form($db);

$search = trim(GETPOST('search', 'alphanohtml'));
$searchStatus = GETPOST('search_status', 'alpha');
$status = ($searchStatus !== '' && is_numeric($searchStatus)) ? (int) $searchStatus : -1;
$searchFkPatient = GETPOSTINT('search_fk_patient');
if (GETPOST('button_removefilter_x', 'alpha') || GETPOST('button_removefilter', 'alpha')) {
	$search = '';
	$status = -1;
	$searchFkPatient = 0;
}

$limit = GETPOSTINT('limit') > 0 ? GETPOSTINT('limit') : $conf->liste_limit;
$page = (int) GETPOST('page', 'int');
if ($page < 0) {
	$page = 0;
}
$offset = $limit * $page;

$dao = new ServiceCard($db);
$result = $dao->search(array('q' => $search, 'status' => $status, 'fk_patient' => $searchFkPatient), $limit, $offset);
if ($result === null) {
	dol_print_error($db, $dao->error);
	exit;
}
$total = $result['total'];
$rows = $result['rows'];

llxHeader('', $langs->trans("ClinicPayCardList"));

$param = '&limit='.(int) $limit;
if ($search !== '') {
	$param .= '&search='.urlencode($search);
}
if ($searchStatus !== '') {
	$param .= '&search_status='.urlencode($searchStatus);
}
if ($searchFkPatient > 0) {
	$param .= '&search_fk_patient='.(int) $searchFkPatient;
}

print '<form method="GET" id="searchFormList" action="'.$_SERVER["PHP_SELF"].'">'."\n";
print '<input type="hidden" name="limit" value="'.(int) $limit.'">';
if ($searchFkPatient > 0) {
	print '<input type="hidden" name="search_fk_patient" value="'.(int) $searchFkPatient.'">';
}

if ($searchFkPatient > 0) {
	$ps = patient_get_summary($db, $searchFkPatient);
	$psName = $ps ? $ps['name'] : '';
	print '<div style="margin-bottom:6px;">';
	print '<span class="opacitymedium">'.$langs->trans('ClinicPayFilterByPatient').'</span> ';
	print '<a href="'.dol_buildpath('/patient/card.php', 1).'?id='.(int) $searchFkPatient.'">'.dol_escape_htmltag($psName).'</a> ';
	print '<a href="'.$_SERVER["PHP_SELF"].'?search_fk_patient=0" class="butActionDeleteSmall">'.$langs->trans('ClearFilter').'</a>';
	print '</div>';
}

print_barre_liste($langs->trans("ClinicPayCardList"), $page, $_SERVER["PHP_SELF"], $param, '', '', '', $total, $total, 'fa-credit-card', 0, '', '', $limit, 0, 0, 1);

if (!empty($user->rights->clinicpay->write) && !empty($user->rights->clinicpay->pay)) {
	print '<div class="tabsAction">';
	print dolGetButtonAction($langs->trans("ClinicPayCardNew"), '', 'default', dol_buildpath('/clinicpay/card.php', 1).'?action=create&token='.newToken(), '', 1);
	print '</div>';
}

$statusOptions = array();
foreach (array(CLINICPAY_CARD_VALID, CLINICPAY_CARD_USED, CLINICPAY_CARD_EXPIRED, CLINICPAY_CARD_REFUNDED) as $st) {
	$statusOptions[(string) $st] = clinicpay_card_status_label($st);
}

print '<div class="div-table-responsive">';
print '<table class="tagtable liste centpercent">'."\n";
print '<tr class="liste_titre_filter">';
print '<td class="liste_titre" colspan="4"><input type="text" name="search" class="minwidth200" placeholder="'.dol_escape_htmltag($langs->trans('ClinicPayCardNo').' / '.$langs->trans('PatientCardNo').' / '.$langs->trans('ThirdPartyName')).'" value="'.dol_escape_htmltag($search).'"></td>';
print '<td class="liste_titre"></td>';
print '<td class="liste_titre"></td>';
// show_empty = -1, not 1: with 1, Form::selectarray() preselects the empty
// option whenever the chosen value equals 1 (its check is `$id ==
// $show_empty`), which made a real status render as the "all" option.
print '<td class="liste_titre center">'.$form->selectarray('search_status', $statusOptions, $status >= 0 ? (string) $status : '', -1, 0, 0, '', 0, 0, 0, '', 'maxwidth100').'</td>';
print '<td class="liste_titre center maxwidthsearch">';
print '<button type="submit" class="liste_titre button_search reposition" name="button_search" value="x"><span class="fa fa-search"></span></button>';
print '<button type="submit" class="liste_titre button_removefilter reposition" name="button_removefilter" value="x"><span class="fa fa-remove"></span></button>';
print '</td></tr>';

print '<tr class="liste_titre">';
print '<th>'.$langs->trans("ClinicPayCardNo").'</th>';
print '<th>'.$langs->trans("PatientCardNo").'</th>';
print '<th>'.$langs->trans("ThirdPartyName").'</th>';
print '<th>'.$langs->trans("ClinicPayCardType").'</th>';
print '<th class="right">'.$langs->trans("ClinicPayCardRemaining").'</th>';
print '<th class="center">'.$langs->trans("ClinicPayCardDateEnd").'</th>';
print '<th class="center">'.$langs->trans("ClinicPayCardStatus").'</th>';
print '<th></th>';
print '</tr>'."\n";

if (empty($rows)) {
	print '<tr><td colspan="8"><span class="opacitymedium">'.$langs->trans("NoRecordFound").'</span></td></tr>';
}

foreach ($rows as $r) {
	$rest = $r->card_type === 'COUNT' ? max(0, (int) $r->total_count - (int) $r->used_count) : max(0, (float) $r->total_value - (float) $r->used_value);
	print '<tr class="oddeven">';
	print '<td><a href="'.dol_buildpath('/clinicpay/card.php', 1).'?id='.(int) $r->rowid.'">'.img_picto('', 'fa-credit-card', 'class="pictofixedwidth"').dol_escape_htmltag($r->ref).'</a></td>';
	print '<td>'.dol_escape_htmltag($r->card_no).'</td>';
	print '<td><a href="'.dol_buildpath('/patient/card.php', 1).'?id='.(int) $r->fk_patient.'">'.dol_escape_htmltag($r->patient_name).'</a></td>';
	print '<td>'.clinicpay_card_type_label($r->card_type).'</td>';
	print '<td class="right">'.($r->card_type === 'COUNT' ? ((int) $rest) : price($rest)).'</td>';
	print '<td class="center">'.($r->date_end ? dol_print_date($db->jdate($r->date_end), 'day') : '').'</td>';
	print '<td class="center">'.clinicpay_card_status_badge($r->status).'</td>';
	print '<td></td>';
	print '</tr>';
}
print '</table>';
print '</div>';
print '</form>';

llxFooter();
$db->close();
