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
 * \file    htdocs/custom/clinicpay/report.php
 * \ingroup clinicpay
 * \brief   Revenue report: bills aggregated by day / by doctor / by pay
 *          channel, plus the flat bill detail underneath. Filters: date
 *          range on llx_clinicpay_bill.date_pay, doctor (through
 *          llx_medrecord.fk_doctor) and channel. Refund offset comes from
 *          the linked credit note (llx_facture type = 2, negative
 *          total_ttc) taken with ABS. Read only.
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

$langs->loadLangs(array("orders", "compta", "clinicpay@clinicpay"));

if (!$user->hasRight('clinicpay', 'read')) {
	accessforbidden();
}

// ---- Dimensions ---------------------------------------------------------------
$dim = GETPOST('dim', 'aZ09');
if (!in_array($dim, array('day', 'doctor', 'channel'), true)) {
	$dim = 'day';
}

// ---- Filters ------------------------------------------------------------------
$dateFrom = dol_mktime(0, 0, 0, GETPOSTINT('search_frommonth'), GETPOSTINT('search_fromday'), GETPOSTINT('search_fromyear'));
$dateTo = dol_mktime(23, 59, 59, GETPOSTINT('search_tomonth'), GETPOSTINT('search_today'), GETPOSTINT('search_toyear'));
$searchFkDoctor = GETPOSTINT('search_fk_doctor');
$searchChannel = trim(GETPOST('search_channel', 'alpha'));
if (GETPOST('button_removefilter_x', 'alpha') || GETPOST('button_removefilter', 'alpha')) {
	$dateFrom = '';
	$dateTo = '';
	$searchFkDoctor = 0;
	$searchChannel = '';
}

// CSV can be exported from any dimension; the queried rows stay the same.
$action = GETPOST('action', 'aZ09');

/**
 * Shared WHERE fragment for bill queries. Returns the conditions string.
 *
 * A bill with an empty date_pay (draft, not yet charged) is only kept when
 * no date range is asked for, so the report never silently hides drafts.
 *
 * @return string SQL conditions
 */
function clinicpayReportWhere($dateFrom, $dateTo, $searchFkDoctor, $searchChannel)
{
	global $db, $conf;

	$sql = " WHERE b.entity = ".((int) $conf->entity);
	if ($dateFrom || $dateTo) {
		$sql .= " AND b.date_pay IS NOT NULL";
		if ($dateFrom) {
			$sql .= " AND b.date_pay >= '".$db->idate($dateFrom)."'";
		}
		if ($dateTo) {
			$sql .= " AND b.date_pay <= '".$db->idate($dateTo)."'";
		}
	}
	if ($searchFkDoctor > 0) {
		$sql .= " AND mr.rowid IS NOT NULL AND mr.fk_doctor = ".((int) $searchFkDoctor);
	}
	if ($searchChannel !== '') {
		$sql .= " AND b.channel = '".$db->escape($searchChannel)."'";
	}
	return $sql;
}

// ---- Dimension + aggregation SQL ---------------------------------------------
$sql = "SELECT ";
if ($dim === 'doctor') {
	$sql .= "mr.fk_doctor AS dim_id, CONCAT_WS(' ', du.lastname, du.firstname) AS dim_label";
	$groupBy = "mr.fk_doctor, CONCAT_WS(' ', du.lastname, du.firstname)";
	$orderBy = "dim_label";
} elseif ($dim === 'channel') {
	$sql .= "b.channel AS dim_id, b.channel AS dim_label";
	$groupBy = "b.channel";
	$orderBy = "dim_label";
} else {
	$sql .= "DATE(b.date_pay) AS dim_id, DATE(b.date_pay) AS dim_label";
	$groupBy = "DATE(b.date_pay)";
	$orderBy = "dim_label";
}
$sql .= ", COUNT(DISTINCT b.rowid) AS nb_bill";
$sql .= ", SUM(b.amount_total) AS amount_all";
// Already settled: a bill is "closed" once it is paid or fully refunded,
// i.e. it left the draft state - refunded rows stay in the total so the
// report reconciles with the invoiced amount.
$sql .= ", SUM(CASE WHEN b.status IN (".CLINICPAY_BILL_PAID.", ".CLINICPAY_BILL_REFUNDED.") THEN b.amount_total ELSE 0 END) AS amount_paid";
// Refund offset: only credit notes (type = 2) hold a negative total_ttc.
$sql .= ", SUM(CASE WHEN f.rowid IS NOT NULL THEN ABS(f.total_ttc) ELSE 0 END) AS amount_refund";
$sql .= " FROM ".MAIN_DB_PREFIX."clinicpay_bill AS b";
$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."medrecord AS mr ON mr.rowid = b.fk_medrecord AND mr.entity = ".((int) $conf->entity);
$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."user AS du ON du.rowid = mr.fk_doctor";
$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."facture AS f ON f.rowid = b.fk_invoice AND f.type = 2 AND f.entity = ".((int) $conf->entity);
$sql .= clinicpayReportWhere($dateFrom, $dateTo, $searchFkDoctor, $searchChannel);
$sql .= " GROUP BY ".$groupBy." ORDER BY ".$orderBy;

$resql = $db->query($sql);
if (!$resql) {
	dol_print_error($db);
	exit;
}
$aggregates = array();
$totals = array('nb_bill' => 0, 'amount_all' => 0, 'amount_paid' => 0, 'amount_refund' => 0);
while ($o = $db->fetch_object($resql)) {
	$aggregates[] = $o;
	$totals['nb_bill'] += (int) $o->nb_bill;
	$totals['amount_all'] += (float) $o->amount_all;
	$totals['amount_paid'] += (float) $o->amount_paid;
	$totals['amount_refund'] += (float) $o->amount_refund;
}
$db->free($resql);
$totals['amount_net'] = $totals['amount_paid'] - $totals['amount_refund'];

// ---- Detail rows (always the same flat bill list) ------------------------------
$sql = "SELECT b.rowid, b.ref, b.status, b.amount_total, b.channel, b.date_pay";
$sql .= ", pp.rowid AS fk_patient, pp.card_no, s.nom AS patient_name";
$sql .= ", CONCAT_WS(' ', du.lastname, du.firstname) AS doctor_label";
$sql .= " FROM ".MAIN_DB_PREFIX."clinicpay_bill AS b";
$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."patient_profile AS pp ON pp.rowid = b.fk_patient";
$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."societe AS s ON s.rowid = pp.fk_soc";
$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."medrecord AS mr ON mr.rowid = b.fk_medrecord AND mr.entity = ".((int) $conf->entity);
$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."user AS du ON du.rowid = mr.fk_doctor";
$sql .= clinicpayReportWhere($dateFrom, $dateTo, $searchFkDoctor, $searchChannel);
$sql .= " ORDER BY b.date_pay DESC, b.rowid DESC";

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
	$csvName = 'clinicpay_report_'.$dim.dol_now('%Y%m%d%H%M%S');
	// Module temp dir: always inside the open_basedir whitelist (d:/dolibarr/...).
	$csvDir = empty($conf->clinicpay->dir_temp) ? DOL_DOCUMENT_ROOT.'/dolibarr_documents/temp/' : $conf->clinicpay->dir_temp;
	$csvFile = $csvDir.'/'.$csvName.'.csv';
	$fh = fopen($csvFile, 'w');
	if ($fh) {
		// UTF-8 BOM so Excel reads the Chinese header/values correctly.
		fwrite($fh, "\xEF\xBB\xBF");
		$head = array(
			$langs->trans('ClinicPayReportColDim'),
			$langs->trans('ClinicPayReportCount'),
			$langs->trans('ClinicPayReportAmountAll'),
			$langs->trans('ClinicPayReportAmountPaid'),
			$langs->trans('ClinicPayReportAmountRefund'),
			$langs->trans('ClinicPayReportAmountNet'),
		);
		fputcsv($fh, $head);
		foreach ($aggregates as $r) {
			fputcsv($fh, array(
				(string) $r->dim_label,
				(int) $r->nb_bill,
				number_format((float) $r->amount_all, 2, '.', ''),
				number_format((float) $r->amount_paid, 2, '.', ''),
				number_format((float) $r->amount_refund, 2, '.', ''),
				number_format((float) $r->amount_paid - (float) $r->amount_refund, 2, '.', ''),
			));
		}
		fputcsv($fh, array(
			$langs->trans('ClinicPayReportTotal'),
			$totals['nb_bill'],
			number_format($totals['amount_all'], 2, '.', ''),
			number_format($totals['amount_paid'], 2, '.', ''),
			number_format($totals['amount_refund'], 2, '.', ''),
			number_format($totals['amount_net'], 2, '.', ''),
		));
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

llxHeader('', $langs->trans("ClinicPayReport"));

print load_fiche_titre(
	$langs->trans("ClinicPayReport"),
	'<a class="butAction" href="'.dol_buildpath('/clinicpay/bill_list.php', 1).'"><span class="fa fa-list fa-fw valignmiddle"></span>'.$langs->trans("ClinicPayReportBackToBill").'</a>',
	'fa-chart-line'
);

$form = new Form($db);

// ---- Filter form --------------------------------------------------------------
print '<form method="GET" action="'.$_SERVER["PHP_SELF"].'" name="formreportfilter">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td colspan="4">'.$langs->trans("ClinicPayReportFilter").'</td></tr>';
print '<tr>';
print '<td class="nowrap">'.$langs->trans("ClinicPayReportDate").'</td><td class="nowrap">';
print $form->selectDate($dateFrom, 'search_from', 0, 0, 1, '', 1, 0).' - ';
print $form->selectDate($dateTo, 'search_to', 0, 0, 1, '', 1, 0);
print '</td>';
print '<td class="nowrap">'.$langs->trans("ClinicPayReportDoctor").'</td><td class="nowrap">';

// Only doctors that actually signed a record of this entity.
$doctors = array();
$resql = $db->query("SELECT DISTINCT u.rowid, CONCAT_WS(' ', u.lastname, u.firstname) AS ufull"
	." FROM ".MAIN_DB_PREFIX."medrecord AS m"
	." LEFT JOIN ".MAIN_DB_PREFIX."user AS u ON u.rowid = m.fk_doctor"
	." WHERE m.entity = ".((int) $conf->entity)." AND m.fk_doctor > 0"
	." ORDER BY ufull");
if ($resql) {
	while ($o = $db->fetch_object($resql)) {
		$doctors[$o->rowid] = $o->ufull;
	}
	$db->free($resql);
}
print '<select name="search_fk_doctor" class="minwidth200">';
print '<option value="0">'.$langs->trans("ClinicPayReportFilterAll").'</option>';
foreach ($doctors as $did => $dlabel) {
	print '<option value="'.(int) $did.'"'.($searchFkDoctor == $did ? ' selected' : '').'>'.dol_escape_htmltag($dlabel).'</option>';
}
print '</select></td>';
print '</tr><tr>';
print '<td class="nowrap">'.$langs->trans("ClinicPayBillChannel").'</td><td class="nowrap"><input name="search_channel" class="minwidth100" value="'.dol_escape_htmltag($searchChannel).'"></td>';
print '<td class="nowrap">'.$langs->trans("ClinicPayReportDim").'</td><td class="nowrap">';
print '<select name="dim" class="minwidth200" onchange="this.form.submit()">';
foreach (array('day' => 'ClinicPayReportDimDay', 'doctor' => 'ClinicPayReportDimDoctor', 'channel' => 'ClinicPayReportDimChannel') as $dv => $dk) {
	print '<option value="'.$dv.'"'.($dim === $dv ? ' selected' : '').'>'.$langs->trans($dk).'</option>';
}
print '</select></td>';
print '</tr><tr>';
print '<td colspan="4" class="center">';
print '<button type="submit" class="button" name="submitfilter" value="1">'.$langs->trans("Refresh").'</button>';
print ' <button type="submit" class="button" name="action" value="export">'.$langs->trans("ClinicPayReportExport").'</button>';
print ' <button type="submit" class="button" name="button_removefilter" value="1">'.$langs->trans("ClearFilter").'</button>';
print '</td></tr>';
print '</table>';
print '</form>';

// ---- Aggregate table ----------------------------------------------------------
print '<div class="fichecenter marginbottomonly">';
print '<span class="badge badge-status4">'.$langs->trans("ClinicPayReportCount").' '.$totals['nb_bill'].'</span> ';
print '<span class="badge badge-status0">'.$langs->trans("ClinicPayReportAmountAll").' '.price($totals['amount_all']).'</span> ';
print '<span class="badge badge-status9">'.$langs->trans("ClinicPayReportAmountPaid").' '.price($totals['amount_paid']).'</span> ';
print '<span class="badge badge-status8">'.$langs->trans("ClinicPayReportAmountRefund").' '.price($totals['amount_refund']).'</span> ';
print '<span class="badge badge-status1">'.$langs->trans("ClinicPayReportAmountNet").' '.price($totals['amount_net']).'</span>';
print '</div>';

$dimLabelKey = $dim === 'doctor' ? 'ClinicPayReportDoctor' : ($dim === 'channel' ? 'ClinicPayBillChannel' : 'ClinicPayReportColDate');
print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<th class="liste_titre">'.$langs->trans($dimLabelKey).'</th>';
print '<th class="liste_titre right">'.$langs->trans("ClinicPayReportCount").'</th>';
print '<th class="liste_titre right">'.$langs->trans("ClinicPayReportAmountAll").'</th>';
print '<th class="liste_titre right">'.$langs->trans("ClinicPayReportAmountPaid").'</th>';
print '<th class="liste_titre right">'.$langs->trans("ClinicPayReportAmountRefund").'</th>';
print '<th class="liste_titre right">'.$langs->trans("ClinicPayReportAmountNet").'</th>';
print '</tr>';

if (empty($aggregates)) {
	print '<tr><td colspan="6"><span class="opacitymedium">'.$langs->trans("ClinicPayReportNoData").'</span></td></tr>';
}
/**
 * Query string that opens the bill list on the slice a report row represents,
 * so a number in the report can be clicked through to the bills behind it.
 *
 * Only the dimensions the list can filter on are offered: the bill list has no
 * doctor filter, so the "by doctor" rows stay plain text on purpose.
 *
 * @param	string			$dim		day | doctor | channel
 * @param	object			$row		Aggregate row (dim_id / dim_label)
 * @return	string						Query string without leading "?", '' when not drillable
 */
function clinicpayReportDrill($dim, $row)
{
	if ($dim === 'day') {
		$ts = strtotime((string) $row->dim_id);
		if (!$ts) {
			return '';
		}
		// The report counts on date_pay, so the list has to filter the same column.
		return 'search_fromyear='.date('Y', $ts).'&search_frommonth='.date('m', $ts).'&search_fromday='.date('d', $ts)
			.'&search_toyear='.date('Y', $ts).'&search_tomonth='.date('m', $ts).'&search_today='.date('d', $ts)
			.'&date_field=date_pay';
	}
	if ($dim === 'channel' && (string) $row->dim_id !== '') {
		$q = 'search_channel='.urlencode((string) $row->dim_id).'&date_field=date_pay';
		if (GETPOSTINT('search_fromyear')) {
			$q .= '&search_fromyear='.GETPOSTINT('search_fromyear').'&search_frommonth='.GETPOSTINT('search_frommonth').'&search_fromday='.GETPOSTINT('search_fromday');
		}
		if (GETPOSTINT('search_toyear')) {
			$q .= '&search_toyear='.GETPOSTINT('search_toyear').'&search_tomonth='.GETPOSTINT('search_tomonth').'&search_today='.GETPOSTINT('search_today');
		}
		return $q;
	}
	return '';
}

foreach ($aggregates as $r) {
	$net = (float) $r->amount_paid - (float) $r->amount_refund;
	$drill = clinicpayReportDrill($dim, $r);
	print '<tr class="oddeven">';
	$label = dol_escape_htmltag((string) $r->dim_label);
	print '<td class="nowrap">'.($drill !== '' ? '<a href="'.dol_buildpath('/clinicpay/bill_list.php', 1).'?'.$drill.'">'.$label.'</a>' : $label).'</td>';
	print '<td class="right">'.(int) $r->nb_bill.'</td>';
	print '<td class="right">'.price((float) $r->amount_all).'</td>';
	print '<td class="right">'.price((float) $r->amount_paid).'</td>';
	print '<td class="right">'.price((float) $r->amount_refund).'</td>';
	print '<td class="right">'.price($net).'</td>';
	print '</tr>';
}
print '<tr class="liste_titre">';
print '<th>'.$langs->trans("ClinicPayReportTotal").'</th>';
print '<th class="right">'.$totals['nb_bill'].'</th>';
print '<th class="right">'.price($totals['amount_all']).'</th>';
print '<th class="right">'.price($totals['amount_paid']).'</th>';
print '<th class="right">'.price($totals['amount_refund']).'</th>';
print '<th class="right">'.price($totals['amount_net']).'</th>';
print '</tr>';
print '</table></div>';

// ---- Detail table -------------------------------------------------------------
print '<div class="fichecenter margin-top">';
print '<span class="opacitymedium">'.$langs->trans("ClinicPayReportDetail").' ('.$nbDetail.')</span>';
print '</div>';

print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<th class="liste_titre">'.$langs->trans("ClinicPayRef").'</th>';
print '<th class="liste_titre">'.$langs->trans("ThirdPartyName").'</th>';
print '<th class="liste_titre">'.$langs->trans("ClinicPayReportDoctor").'</th>';
print '<th class="liste_titre center">'.$langs->trans("ClinicPayBillChannel").'</th>';
print '<th class="liste_titre center nowrap">'.$langs->trans("ClinicPayBillDate").'</th>';
print '<th class="liste_titre right">'.$langs->trans("ClinicPayBillAmount").'</th>';
print '<th class="liste_titre center">'.$langs->trans("Status").'</th>';
print '</tr>';

if (empty($details)) {
	print '<tr><td colspan="7"><span class="opacitymedium">'.$langs->trans("ClinicPayReportDetailEmpty").'</span></td></tr>';
}
$detailParam = '';
if ($dateFrom) {
	$detailParam .= '&search_fromday='.GETPOSTINT('search_fromday').'&search_frommonth='.GETPOSTINT('search_frommonth').'&search_fromyear='.GETPOSTINT('search_fromyear');
}
if ($dateTo) {
	$detailParam .= '&search_today='.GETPOSTINT('search_today').'&search_tomonth='.GETPOSTINT('search_tomonth').'&search_toyear='.GETPOSTINT('search_toyear');
}
foreach ($details as $r) {
	print '<tr class="oddeven">';
	// The ref drills down into the bill list filtered on the same range.
	print '<td><a href="'.dol_buildpath('/clinicpay/bill_list.php', 1).$detailParam.'">'.img_picto('', 'fa-credit-card', 'class="pictofixedwidth"').dol_escape_htmltag($r->ref).'</a></td>';
	print '<td>'.dol_escape_htmltag((string) $r->patient_name).'</td>';
	print '<td>'.dol_escape_htmltag((string) $r->doctor_label).'</td>';
	print '<td class="center">'.dol_escape_htmltag((string) $r->channel).'</td>';
	print '<td class="center nowrap">'.($r->date_pay ? dol_print_date($db->jdate($r->date_pay), 'dayhour') : '').'</td>';
	print '<td class="right">'.price((float) $r->amount_total).'</td>';
	print '<td class="center">'.clinicpay_bill_status_badge($r->status).'</td>';
	print '</tr>';
}
print '</table></div>';

llxFooter();
$db->close();
