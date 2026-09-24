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
 * \file    htdocs/custom/clinicpay/bill.php
 * \ingroup clinicpay
 * \brief   Charge bill card (spec §3.6): creation with price-snapshot line
 *          editor, confirm (native invoice + payment, one transaction) and
 *          the two-step refund entry. Phase 2.
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
dol_include_once('/clinicpay/class/paybill.class.php');

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

$id = GETPOSTINT('id');
$action = GETPOST('action', 'aZ09');
$confirm = GETPOST('confirm', 'alpha');
$fkPatientParam = GETPOSTINT('fk_patient');
$token = GETPOST('token', 'alpha');

$form = new Form($db);
$dao = new Paybill($db);
// POST handlers below (confirm / refund_draft / confirm_refund) rely on a
// loaded $dao; the view fetch happens later, so load it here as well.
if ($id > 0) {
	$dao->fetch($id);
}

/** Report a class failure as a flash message and go back to $url. */
function clinicpay_redirect_error($error, $url)
{
	global $langs;
	$translated = $langs->trans($error);
	setEventMessages($translated !== $error ? $translated : $error, null, 'errors');
	header('Location: '.$url);
	exit;
}

// ------------------------------------------------------------ create form
if ($action == 'create') {
	if (!$user->hasRight('clinicpay', 'write')) {
		accessforbidden();
	}
	if ($fkPatientParam <= 0) {
		accessforbidden($langs->trans("ClinicPayErrPatient"));
	}
	$summary = patient_get_summary($db, $fkPatientParam);
	if (!$summary) {
		accessforbidden($langs->trans("ErrorRecordNotFound"));
	}
	$products = clinicpay_product_options($db);

	llxHeader('', $langs->trans("ClinicPayBillNew"));
	print patient_summary_banner($summary, array(array('label' => $langs->trans('ClinicPayBillTab'), 'url' => dol_buildpath('/clinicpay/patient_tab.php', 1).'?tab=bills&id='.((int) $fkPatientParam))), 'clinicpay');

	print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="add">';
	print '<input type="hidden" name="fk_patient" value="'.((int) $fkPatientParam).'">';

	print '<table class="border centpercent tableforfieldcreate">';
	print '<tr><td class="titlefieldcreate">'.$langs->trans("ClinicPayPatient").'</td><td>'.dol_escape_htmltag($summary['name']).'</td></tr>';
	print '<tr><td class="titlefieldcreate">'.$langs->trans("ClinicPayBillNote").'</td><td><textarea class="flat" name="note" rows="2" cols="60"></textarea></td></tr>';
	print '</table>';

	print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
	print '<tr class="liste_titre"><th>#</th><th>'.$langs->trans("ClinicPayBillProduct").'</th><th class="right" style="width:120px">'.$langs->trans("ClinicPayBillQty").'</th></tr>';
	$lineCount = 8;
	for ($i = 0; $i < $lineCount; $i++) {
		print '<tr class="oddeven">';
		print '<td>'.($i + 1).'</td>';
		print '<td>';
		print '<select class="flat minwidth300" name="fk_product['.$i.']">';
		print '<option value="0">-</option>';
		foreach ($products as $pid => $plabel) {
			print '<option value="'.$pid.'">'.dol_escape_htmltag($plabel).'</option>';
		}
		print '</select></td>';
		print '<td class="right"><input class="flat maxwidth75 right" type="text" name="qty['.$i.']" value=""></td>';
		print '</tr>';
	}
	print '</table></div>';

	print '<div class="opacitymedium paddingbottom">'.$langs->trans("ClinicPayBillSnapshotNote").'</div>';
	print $form->buttonsSaveCancel("ClinicPayBillNew", "Cancel");
	print '</form>';
	llxFooter();
	$db->close();
	exit;
}

// ------------------------------------------------------------ add (create submission)
if ($action == 'add' && $token != '' && GETPOST('save', 'alpha') !== '') {
	if (!$user->hasRight('clinicpay', 'write')) {
		accessforbidden();
	}
	$lines = array();
	$fkProducts = GETPOST('fk_product', 'array');
	$qtys = GETPOST('qty', 'array');
	foreach ($fkProducts as $i => $pid) {
		$pid = (int) $pid;
		$qty = isset($qtys[$i]) ? (float) $qtys[$i] : 0;
		if ($pid > 0 && $qty > 0) {
			$lines[] = array('fk_product' => $pid, 'qty' => $qty);
		}
	}
	$data = array('fk_patient' => GETPOSTINT('fk_patient'), 'note' => (string) GETPOST('note', 'restricthtml'), 'lines' => $lines);
	$result = $dao->create($user, $data);
	if ($result > 0) {
		setEventMessages($langs->trans("RecordSaved").' '.$dao->ref, null, 'mesgs');
		header('Location: '.$_SERVER["PHP_SELF"].'?id='.$dao->id);
		exit;
	}
	clinicpay_redirect_error($dao->error, $_SERVER["PHP_SELF"].'?action=create&fk_patient='.((int) $data['fk_patient']).'&token='.newToken());
}

// ------------------------------------------------------------ confirm charge
if ($action == 'confirm' && $id > 0 && $token != '') {
	if (!$user->hasRight('clinicpay', 'pay')) {
		accessforbidden();
	}
	$channel = GETPOST('channel', 'alpha');
	$channelRef = (string) GETPOST('channel_ref', 'alpha');
	$result = $dao->confirm($user, $channel, $channelRef);
	if ($result > 0) {
		setEventMessages($langs->trans("ClinicPayBillConfirmed").' '.$dao->ref, null, 'mesgs');
	} else {
		clinicpay_redirect_error($dao->error, $_SERVER["PHP_SELF"].'?id='.$id);
	}
	header('Location: '.$_SERVER["PHP_SELF"].'?id='.$id);
	exit;
}

// ------------------------------------------------------------ refund: step 1 draft (write)
if ($action == 'refund_draft' && $id > 0 && $token != '') {
	if (!$user->hasRight('clinicpay', 'write')) {
		accessforbidden();
	}
	$reason = (string) GETPOST('refund_reason', 'restricthtml');
	$result = $dao->createRefundDraft($user, $reason);
	if ($result > 0) {
		setEventMessages($langs->trans("ClinicPayRefundDraftCreated"), null, 'mesgs');
	} else {
		clinicpay_redirect_error($dao->error, $_SERVER["PHP_SELF"].'?id='.$id);
	}
	header('Location: '.$_SERVER["PHP_SELF"].'?id='.$id);
	exit;
}

// ------------------------------------------------------------ refund: step 2 execute (validate)
if ($action == 'confirm_refund' && $confirm == 'yes' && $id > 0 && $token != '') {
	if (!$user->hasRight('clinicpay', 'validate')) {
		accessforbidden();
	}
	$result = $dao->executeRefund($user);
	if ($result > 0) {
		setEventMessages($langs->trans("ClinicPayRefundDone").' '.$dao->ref, null, 'mesgs');
	} else {
		clinicpay_redirect_error($dao->error, $_SERVER["PHP_SELF"].'?id='.$id);
	}
	header('Location: '.$_SERVER["PHP_SELF"].'?id='.$id);
	exit;
}

// ------------------------------------------------------------ card view
if ($id <= 0 || $dao->fetch($id) <= 0) {
	llxHeader('', $langs->trans("ClinicPayBill"));
	print '<div class="error">'.$langs->trans("NoRecordFound").'</div>';
	llxFooter();
	$db->close();
	exit;
}
patient_audit($db, $dao->fk_patient, 'CLINICPAY_READ', $user, array('ref' => $dao->ref, 'bill' => $dao->id, 'via' => 'ui'));

$summary = patient_get_summary($db, $dao->fk_patient);
$isDraft = (int) $dao->status === CLINICPAY_BILL_DRAFT;
$isPaid = (int) $dao->status === CLINICPAY_BILL_PAID;
$isRefunded = (int) $dao->status === CLINICPAY_BILL_REFUNDED;

llxHeader('', $langs->trans("ClinicPayRef").' '.$dao->ref);

$trail = array();
$trail[] = array('label' => $langs->trans('ClinicPayBillTab'), 'url' => dol_buildpath('/clinicpay/patient_tab.php', 1).'?tab=bills&id='.((int) $dao->fk_patient));
print patient_summary_banner($summary, $trail, 'clinicpay');

print load_fiche_titre($langs->trans("ClinicPayBill").' '.dol_escape_htmltag($dao->ref).' '.clinicpay_bill_status_badge($dao->status), '', 'fa-credit-card');

print '<table class="border centpercent tableforfield">';
print '<tr><td class="titlefield">'.$langs->trans("ClinicPayPatient").'</td><td>';
print '<a href="'.dol_buildpath('/patient/card.php', 1).'?id='.(int) $dao->fk_patient.'">'.dol_escape_htmltag($summary ? $summary['name'] : '').'</a></td>';
print '<td class="titlefield">'.$langs->trans("ClinicPayBillAmount").'</td><td><span class="amount">'.price($dao->amount_total, 2, '', 1, -1, -1, 'auto').'</span></td></tr>';
print '<tr><td>'.$langs->trans("ClinicPayBillChannel").'</td><td>'.dol_escape_htmltag(clinicpay_channel_label($dao->channel));
if ($dao->channel_ref !== '') {
	print ' <span class="opacitymedium">('.dol_escape_htmltag($dao->channel_ref).')</span>';
}
print '</td>';
print '<td>'.$langs->trans("ClinicPayBillPayDate").'</td><td>'.($dao->date_pay ? dol_print_date($dao->date_pay, 'dayhour') : '').'</td></tr>';
print '<tr><td>'.$langs->trans("ClinicPayInvoice").'</td><td>';
if ((int) $dao->fk_invoice > 0) {
	print '<a href="'.DOL_URL_ROOT.'/compta/facture/card.php?id='.((int) $dao->fk_invoice).'">'.dol_escape_htmltag($langs->trans("Invoice").' #'.(int) $dao->fk_invoice).'</a>';
} else {
	print '<span class="opacitymedium">-</span>';
}
print '</td>';
print '<td>'.$langs->trans("Documents").'</td><td>';
if ($isPaid) {
	print '<a href="'.dol_buildpath('/clinicpay/pdf.php', 1).'?id='.((int) $dao->id).'" target="_blank" rel="noopener">'.dol_escape_htmltag($langs->trans("ClinicPayPdfView").' (SF PDF)').'</a>';
} else {
	print '<span class="opacitymedium">-</span>';
}
print '</td></tr>';
print '<tr><td>'.$langs->trans("DateCreation").'</td><td colspan="3">'.dol_print_date($dao->date_creation, 'dayhour').'</td></tr>';
if ($dao->note) {
	print '<tr><td>'.$langs->trans("ClinicPayBillNote").'</td><td colspan="3">'.dol_escape_htmltag($dao->note).'</td></tr>';
}
print '</table>';

print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
print '<tr class="liste_titre"><th>#</th><th>'.$langs->trans("ClinicPayBillProduct").'</th><th class="right">'.$langs->trans("ClinicPayBillQty").'</th><th class="right">'.$langs->trans("ClinicPayBillPriceUnit").'</th><th class="right">'.$langs->trans("ClinicPayBillVat").'</th><th class="right">'.$langs->trans("ClinicPayBillSubtotal").'</th></tr>';
if (empty($dao->lines)) {
	print '<tr><td colspan="6"><span class="opacitymedium">'.$langs->trans("NoRecordFound").'</span></td></tr>';
}
foreach ($dao->lines as $i => $ln) {
	print '<tr class="oddeven">';
	print '<td>'.($i + 1).'</td>';
	print '<td>'.dol_escape_htmltag($ln['label']).'</td>';
	print '<td class="right">'.price2num($ln['qty'], 'MS').'</td>';
	print '<td class="right">'.price($ln['price_unit']).'</td>';
	print '<td class="right">'.price2num($ln['vat_rate'], 'MU').'%</td>';
	print '<td class="right">'.price($ln['subprice_total']).'</td>';
	print '</tr>';
}
print '<tr class="liste_total"><td colspan="5" class="right">'.$langs->trans("Total").'</td><td class="right">'.price($dao->amount_total).'</td></tr>';
print '</table></div>';

// Actions
print '<div class="tabsAction">';
if ($isPaid && $user->hasRight('clinicpay', 'write')) {
	print dolGetButtonAction($langs->trans("ClinicPayRefundStart"), '', 'delete', $_SERVER["PHP_SELF"].'?id='.$dao->id.'&action=refund&token='.newToken(), '', 1);
}
if ($isPaid) {
	$draftId = $dao->findRefundDraft();
	if ($draftId > 0) {
		print '<div class="info">'.$langs->trans("ClinicPayRefundDraftPending").' <a href="'.DOL_URL_ROOT.'/compta/facture/card.php?id='.((int) $draftId).'">'.dol_escape_htmltag($langs->trans("Invoice").' #'.(int) $draftId).'</a>';
		if ($user->hasRight('clinicpay', 'validate')) {
			print ' — <a href="'.$_SERVER["PHP_SELF"].'?id='.$dao->id.'&action=refund&token='.newToken().'">'.$langs->trans("ClinicPayRefundExecute").'</a>';
		}
		print '</div>';
	}
}
print '</div>';

// Inline confirm-charge form (draft, pay permission)
if ($isDraft && $user->hasRight('clinicpay', 'pay')) {
	print load_fiche_titre($langs->trans("ClinicPayBillConfirmTitle"), '', 'fa-money-check-alt');
	print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="confirm">';
	print '<input type="hidden" name="id" value="'.((int) $dao->id).'">';
	print '<table class="border centpercent tableforfield">';
	print '<tr><td class="titlefield fieldrequired">'.$langs->trans("ClinicPayBillChannel").'</td><td>';
	print '<select class="flat" name="channel">';
	print '<option value="CASH">'.dol_escape_htmltag(clinicpay_channel_label(CLINICPAY_CHANNEL_CASH)).'</option>';
	print '<option value="SCAN">'.dol_escape_htmltag(clinicpay_channel_label(CLINICPAY_CHANNEL_SCAN)).'</option>';
	print '</select></td></tr>';
	print '<tr><td>'.$langs->trans("ClinicPayBillChannelRef").'</td><td><input class="flat minwidth200" type="text" name="channel_ref" value=""> <span class="opacitymedium">'.$langs->trans("ClinicPayChannelRefHint").'</span></td></tr>';
	print '</table>';
	print '<div class="center paddingtop"><input type="submit" class="button" value="'.dol_escape_htmltag($langs->trans("ClinicPayBillConfirmTitle")).'"></div>';
	print '</form>';
}

// Refund forms (reason input for step 1; formconfirm for step 2)
if ($action == 'refund' && $isPaid && ($user->hasRight('clinicpay', 'write') || $user->hasRight('clinicpay', 'validate'))) {
	$draftId = $dao->findRefundDraft();
	if ($draftId <= 0 && $user->hasRight('clinicpay', 'write')) {
		print $form->formconfirm($_SERVER["PHP_SELF"].'?id='.$dao->id, $langs->trans("ClinicPayRefundStart"), $langs->trans("ClinicPayRefundReasonAsk"), 'refund_draft', array(array('type' => 'text', 'name' => 'refund_reason', 'label' => $langs->trans("ClinicPayRefundReason"), 'value' => '', 'size' => 60)), 0, 1);
	} elseif ($draftId > 0 && $user->hasRight('clinicpay', 'validate')) {
		print $form->formconfirm($_SERVER["PHP_SELF"].'?id='.$dao->id, $langs->trans("ClinicPayRefundExecute"), $langs->trans("ClinicPayRefundConfirmAsk"), 'confirm_refund', array(), 0, 1);
	}
}

llxFooter();
$db->close();
