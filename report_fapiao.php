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
 * \file    htdocs/custom/clinicpay/report_fapiao.php
 * \ingroup clinicpay
 * \brief   Tax invoice reconciliation: which paid bills still miss the invoice
 *          number the cashier filled back from the tax platform.
 *
 * Design notes:
 * - Reconciliation runs on date_pay (money actually received), not on
 *   date_creation, so a bill paid at the end of a month belongs to that month.
 * - "Missing" means fapiao_no is NULL or blank. The number is registered by
 *   hand after the tax platform issues it (see Paybill::setFapiaoNo), so this
 *   page is the worklist of what still has to be written back.
 * - Draft bills (no date_pay) are out of scope: nothing was collected yet.
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

$langs->loadLangs(array("patient@patient", "clinicpay@clinicpay"));

if (!$user->hasRight('clinicpay', 'read')) {
	accessforbidden();
}

// ---- Filters ------------------------------------------------------------------
$dateFrom = dol_mktime(0, 0, 0, GETPOSTINT('search_frommonth'), GETPOSTINT('search_fromday'), GETPOSTINT('search_fromyear'));
$dateTo = dol_mktime(23, 59, 59, GETPOSTINT('search_tomonth'), GETPOSTINT('search_today'), GETPOSTINT('search_toyear'));
// GETPOSTINT returns 0 when absent, and dol_mktime() then lands in 1899, so a
// negative result means "no filter given". Reconciliation defaults to the last
// 30 days: the same window as the dashboard, and it always covers the books
// the accountant is closing rather than a single calendar month.
if ($dateFrom <= 0 && $dateTo <= 0) {
	$dateFrom = dol_time_plus_duree(dol_now(), -29, 'd');
	$dateTo = dol_now();
}
$searchStatus = GETPOST('search_status', 'alpha');
$regFilter = GETPOST('search_registered', 'alpha');
if (!in_array($regFilter, array('registered', 'missing'), true)) {
	$regFilter = '';
}
$action = GETPOST('action', 'aZ09');
if (GETPOST('button_removefilter_x', 'alpha') || GETPOST('button_removefilter', 'alpha')) {
	$dateFrom = dol_time_plus_duree(dol_now(), -29, 'd');
	$dateTo = dol_now();
	$searchStatus = '';
	$regFilter = '';
}

$P = $db->prefix();
// A missing invoice number, expressed once so totals and rows cannot disagree.
$missExpr = "(b.fapiao_no IS NULL OR TRIM(b.fapiao_no) = '')";

$from = " FROM ".$P."clinicpay_bill AS b";
$from .= " LEFT JOIN ".$P."patient_profile AS pp ON pp.rowid = b.fk_patient";
$from .= " LEFT JOIN ".$P."societe AS s ON s.rowid = pp.fk_soc";

$where = " WHERE b.entity = ".((int) $conf->entity);
$where .= " AND b.date_pay IS NOT NULL";
if ($dateFrom > 0) {
	$where .= " AND b.date_pay >= '".$db->idate($dateFrom)."'";
}
if ($dateTo > 0) {
	$where .= " AND b.date_pay <= '".$db->idate($dateTo)."'";
}
if ($searchStatus !== '' && is_numeric($searchStatus)) {
	$where .= " AND b.status = ".((int) $searchStatus);
}
if ($regFilter === 'missing') {
	$where .= " AND ".$missExpr;
} elseif ($regFilter === 'registered') {
	$where .= " AND NOT ".$missExpr;
}

// ---- Totals -------------------------------------------------------------------
$sql = "SELECT COUNT(*) AS nb_bill, COALESCE(SUM(b.amount_total),0) AS amount_all";
$sql .= ", COALESCE(SUM(CASE WHEN ".$missExpr." THEN 1 ELSE 0 END),0) AS nb_missing";
$sql .= ", COALESCE(SUM(CASE WHEN ".$missExpr." THEN b.amount_total ELSE 0 END),0) AS amount_missing";
$sql .= $from.$where;
$resql = $db->query($sql);
if (!$resql) {
	dol_print_error($db);
	exit;
}
$totals = $db->fetch_object($resql);
$db->free($resql);

$amountRegistered = (float) $totals->amount_all - (float) $totals->amount_missing;
$rate = ((float) $totals->amount_all > 0) ? round(100 * $amountRegistered / (float) $totals->amount_all, 1) : 0;

// ---- Monthly breakdown --------------------------------------------------------
$sql = "SELECT DATE_FORMAT(b.date_pay, '%Y-%m') AS ym";
$sql .= ", COUNT(*) AS nb_bill, COALESCE(SUM(b.amount_total),0) AS amount_all";
$sql .= ", COALESCE(SUM(CASE WHEN ".$missExpr." THEN 1 ELSE 0 END),0) AS nb_missing";
$sql .= ", COALESCE(SUM(CASE WHEN ".$missExpr." THEN b.amount_total ELSE 0 END),0) AS amount_missing";
$sql .= $from.$where." GROUP BY DATE_FORMAT(b.date_pay, '%Y-%m') ORDER BY ym DESC";
$resql = $db->query($sql);
$months = array();
if ($resql) {
	while ($o = $db->fetch_object($resql)) {
		$months[] = $o;
	}
	$db->free($resql);
}

// ---- Detail -------------------------------------------------------------------
$sql = "SELECT b.rowid, b.ref, b.amount_total, b.channel, b.date_pay, b.status, b.fapiao_no";
$sql .= ", pp.card_no, s.nom AS patient_name";
$sql .= $from.$where." ORDER BY b.date_pay DESC, b.rowid DESC";
$resql = $db->query($sql);
if (!$resql) {
	dol_print_error($db);
	exit;
}
$details = array();
while ($o = $db->fetch_object($resql)) {
	$details[] = $o;
}
$nbDetail = count($details);
$db->free($resql);

// ---- CSV (must run before any output) ----------------------------------------
if ($action === 'export') {
	$csvName = 'clinicpay_fapiao_'.dol_now('%Y%m%d%H%M%S');
	$csvDir = empty($conf->clinicpay->dir_temp) ? DOL_DOCUMENT_ROOT.'/dolibarr_documents/temp/' : $conf->clinicpay->dir_temp;
	$csvFile = $csvDir.'/'.$csvName.'.csv';
	$fh = fopen($csvFile, 'w');
	if ($fh) {
		// UTF-8 BOM so Excel reads the Chinese header and values correctly.
		fwrite($fh, "\xEF\xBB\xBF");
		fputcsv($fh, array(
			$langs->trans('ClinicPayRef'),
			$langs->trans('PatientCardNo'),
			$langs->trans('ThirdPartyName'),
			$langs->trans('ClinicPayBillAmount'),
			$langs->trans('ClinicPayBillChannel'),
			$langs->trans('ClinicPayFapiaoPayDate'),
			$langs->trans('ClinicPayFapiaoNo'),
			$langs->trans('Status'),
		));
		foreach ($details as $r) {
			fputcsv($fh, array(
				(string) $r->ref,
				(string) $r->card_no,
				(string) $r->patient_name,
				number_format((float) $r->amount_total, 2, '.', ''),
				(string) $r->channel,
				$r->date_pay ? dol_print_date($db->jdate($r->date_pay), 'dayhour') : '',
				(string) $r->fapiao_no,
				clinicpay_bill_status_label((string) $r->status),
			));
		}
		fclose($fh);
		top_httphead('text/csv; charset=UTF-8');
		header('Content-Description: File Transfer');
		header('Content-Disposition: attachment; filename="'.$csvName.'.csv"');
		header('Cache-Control: Public, must-revalidate');
		header('Pragma: public');
		readfile($csvFile);
		exit;
	}
	setEventMessages($langs->trans('ClinicPayReportExportFailed'), null, 'errors');
}

llxHeader('', $langs->trans("ClinicPayFapiaoReport"));

print load_fiche_titre(
	$langs->trans("ClinicPayFapiaoReport"),
	'<a class="butAction" href="'.dol_buildpath('/clinicpay/bill_list.php', 1).'"><span class="fa fa-list fa-fw valignmiddle"></span>'.$langs->trans("ClinicPayReportBackToBill").'</a>',
	'fa-receipt'
);

$form = new Form($db);

// ---- Filter form --------------------------------------------------------------
print '<form method="GET" action="'.$_SERVER["PHP_SELF"].'" name="formfapiao">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td colspan="4">'.$langs->trans("ClinicPayReportFilter").'</td></tr>';
print '<tr>';
print '<td class="nowrap">'.$langs->trans("ClinicPayReportDate").'</td><td class="nowrap">';
print $form->selectDate($dateFrom, 'search_from', 0, 0, 1, '', 1, 0).' - ';
print $form->selectDate($dateTo, 'search_to', 0, 0, 1, '', 1, 0);
print '</td>';
print '<td class="nowrap">'.$langs->trans("Status").'</td><td class="nowrap">';

$statusOptions = array('' => $langs->trans("ClinicPayFapiaoAllStatuses"));
foreach (array(CLINICPAY_BILL_PAID, CLINICPAY_BILL_REFUNDED) as $st) {
	$statusOptions[(string) $st] = clinicpay_bill_status_label($st);
}
print $form->selectarray('search_status', $statusOptions, $searchStatus, 0, 0, 0, '', 0, 0, 0, '', 'maxwidth150');
print '</td></tr>';
print '<tr>';
print '<td class="nowrap">'.$langs->trans("ClinicPayFapiaoRegisterState").'</td><td class="nowrap">';

$regOptions = array(
	'' => $langs->trans("ClinicPayFapiaoAll"),
	'registered' => $langs->trans("ClinicPayFapiaoRegistered"),
	'missing' => $langs->trans("ClinicPayFapiaoMissing"),
);
print $form->selectarray('search_registered', $regOptions, $regFilter, 0, 0, 0, '', 0, 0, 0, '', 'maxwidth150');
print '</td>';
print '<td colspan="2" class="opacitymedium">'.$langs->trans("ClinicPayFapiaoTip").'</td></tr>';
print '<tr>';
print '<td colspan="4" class="center">';
print '<button type="submit" class="button" name="submitfilter" value="1">'.$langs->trans("Refresh").'</button>';
print ' <button type="submit" class="button" name="action" value="export">'.$langs->trans("ClinicPayFapiaoExport").'</button>';
print ' <button type="submit" class="button" name="button_removefilter" value="1">'.$langs->trans("ClearFilter").'</button>';
print '</td></tr>';
print '</table>';
print '</form>';

// ---- Totals -------------------------------------------------------------------
print '<div class="fichecenter marginbottomonly">';
print '<span class="badge badge-status4">'.$langs->trans("ClinicPayReportCount").' '.(int) $totals->nb_bill.'</span> ';
print '<span class="badge badge-status1">'.$langs->trans("ClinicPayFapiaoAmountAll").' '.price($totals->amount_all).'</span> ';
print '<span class="badge badge-status8">'.$langs->trans("ClinicPayFapiaoAmountRegistered").' '.price($amountRegistered).'</span> ';
print '<span class="badge badge-status9">'.$langs->trans("ClinicPayFapiaoAmountMissing").' '.price($totals->amount_missing).' ('.(int) $totals->nb_missing.')</span> ';
print '<span class="badge badge-status2">'.$langs->trans("ClinicPayFapiaoRate").' '.$rate.'%</span>';
print '</div>';

// ---- Monthly table ------------------------------------------------------------
print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<th class="liste_titre">'.$langs->trans("ClinicPayFapiaoMonth").'</th>';
print '<th class="liste_titre right">'.$langs->trans("ClinicPayReportCount").'</th>';
print '<th class="liste_titre right">'.$langs->trans("ClinicPayFapiaoAmountAll").'</th>';
print '<th class="liste_titre right">'.$langs->trans("ClinicPayFapiaoAmountRegistered").'</th>';
print '<th class="liste_titre right">'.$langs->trans("ClinicPayFapiaoAmountMissing").'</th>';
print '<th class="liste_titre right">'.$langs->trans("ClinicPayFapiaoRate").'</th>';
print '</tr>';

if (empty($months)) {
	print '<tr><td colspan="6"><span class="opacitymedium">'.$langs->trans("ClinicPayFapiaoNoData").'</span></td></tr>';
}
foreach ($months as $r) {
	$reg = (float) $r->amount_all - (float) $r->amount_missing;
	$mrate = ((float) $r->amount_all > 0) ? round(100 * $reg / (float) $r->amount_all, 1) : 0;
	print '<tr class="oddeven">';
	print '<td class="nowrap">'.dol_escape_htmltag((string) $r->ym).'</td>';
	print '<td class="right">'.(int) $r->nb_bill.'</td>';
	print '<td class="right">'.price((float) $r->amount_all).'</td>';
	print '<td class="right">'.price($reg).'</td>';
	print '<td class="right">'.price((float) $r->amount_missing).'</td>';
	print '<td class="right">'.$mrate.'%</td>';
	print '</tr>';
}
print '</table></div>';

// ---- Detail table -------------------------------------------------------------
print '<div class="fichecenter margin-top"><span class="opacitymedium">';
print $langs->trans("ClinicPayFapiaoDetail").' ('.$nbDetail.')';
print '</span></div>';

print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<th class="liste_titre">'.$langs->trans("ClinicPayRef").'</th>';
print '<th class="liste_titre">'.$langs->trans("PatientCardNo").'</th>';
print '<th class="liste_titre">'.$langs->trans("ThirdPartyName").'</th>';
print '<th class="liste_titre right">'.$langs->trans("ClinicPayBillAmount").'</th>';
print '<th class="liste_titre center">'.$langs->trans("ClinicPayBillChannel").'</th>';
print '<th class="liste_titre center nowrap">'.$langs->trans("ClinicPayFapiaoPayDate").'</th>';
print '<th class="liste_titre center">'.$langs->trans("ClinicPayFapiaoNo").'</th>';
print '<th class="liste_titre center">'.$langs->trans("Status").'</th>';
print '</tr>';

if (empty($details)) {
	print '<tr><td colspan="8"><span class="opacitymedium">'.$langs->trans("ClinicPayFapiaoNoData").'</span></td></tr>';
}
foreach ($details as $r) {
	$fapiao = trim((string) $r->fapiao_no);
	print '<tr class="oddeven">';
	// The ref opens the bill, which is where the number is registered.
	print '<td><a href="'.dol_buildpath('/clinicpay/bill.php', 1).'?id='.(int) $r->rowid.'">'.img_picto('', 'fa-credit-card', 'class="pictofixedwidth"').dol_escape_htmltag((string) $r->ref).'</a></td>';
	print '<td>'.dol_escape_htmltag((string) $r->card_no).'</td>';
	print '<td>'.dol_escape_htmltag((string) $r->patient_name).'</td>';
	print '<td class="right">'.price((float) $r->amount_total).'</td>';
	print '<td class="center">'.dol_escape_htmltag(clinicpay_channel_label((string) $r->channel)).'</td>';
	print '<td class="center nowrap">'.($r->date_pay ? dol_print_date($db->jdate($r->date_pay), 'dayhour') : '').'</td>';
	if ($fapiao === '') {
		print '<td class="center error">'.$langs->trans("ClinicPayFapiaoMissing").'</td>';
	} else {
		print '<td class="center">'.dol_escape_htmltag($fapiao).'</td>';
	}
	print '<td class="center">'.clinicpay_bill_status_badge($r->status).'</td>';
	print '</tr>';
}
print '</table></div>';

llxFooter();
$db->close();
