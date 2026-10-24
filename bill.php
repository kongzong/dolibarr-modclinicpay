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
dol_include_once('/clinicpay/class/servicecard.class.php');
dol_include_once('/pharmacy/class/dispense.class.php');
dol_include_once('/clinicpay/class/bundle.class.php');
dol_include_once('/prescription/class/prescriptionsheet.class.php');

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

$langs->loadLangs(array("patient@patient", "clinicpay@clinicpay", "pharmacy@pharmacy"));

if (!$user->hasRight('clinicpay', 'read')) {
	accessforbidden();
}

$id = GETPOSTINT('id');
$action = GETPOST('action', 'aZ09');
$confirm = GETPOST('confirm', 'alpha');
$fkPatientParam = GETPOSTINT('fk_patient');
$fkMedrecordParam = GETPOSTINT('fk_medrecord');
$fkDispenseParam = GETPOSTINT('fk_dispense');
$fkPrescriptionParam = GETPOSTINT('fk_prescription');
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
	$productPrices = clinicpay_product_prices($db);

	// Dispense linkage: pre-fill lines and anchor the visit (medrecord)
	$dispensePrefill = array();
	$fromDispenseRef = '';
	$fromPrescRef = '';
	if ($fkDispenseParam > 0) {
		$disp = new Dispense($db);
		if ($disp->fetch($fkDispenseParam) > 0 && (int) $disp->status === PHARMACY_STATUS_DISPENSED && (int) $disp->fk_patient === $fkPatientParam) {
			$fromDispenseRef = $disp->ref;
			$fkPresc = (int) $disp->fk_prescription;
			if ($fkPresc > 0) {
				$ps = new PrescriptionSheet($db);
				if ($ps->fetch($fkPresc) > 0) {
					$fromPrescRef = $ps->ref;
					if ((int) $ps->fk_medrecord > 0) {
						$fkMedrecordParam = (int) $ps->fk_medrecord;
					}
				}
			}
			foreach ($disp->lines as $dl) {
				if (!empty($dl['fk_product']) && !empty($dl['qty'])) {
					$dispensePrefill[] = array(
						'fk_product' => (int) $dl['fk_product'],
						'qty' => (float) $dl['qty'],
						'fk_prescription' => $fkPresc,
						'fk_dispense' => (int) $disp->id,
					);
				}
			}
		}
	}

	// Prescription linkage (direct entry, no dispense): pre-fill lines, anchor the visit
	if ($fkPrescriptionParam > 0 && empty($dispensePrefill)) {
		$ps = new PrescriptionSheet($db);
		if ($ps->fetch($fkPrescriptionParam) > 0 && (int) $ps->fk_patient === $fkPatientParam) {
			$fromPrescRef = $ps->ref;
			if ((int) $ps->fk_medrecord > 0) {
				$fkMedrecordParam = (int) $ps->fk_medrecord;
			}
			foreach ($ps->lines as $pl) {
				if (!empty($pl['fk_product']) && !empty($pl['qty'])) {
					$dispensePrefill[] = array(
						'fk_product' => (int) $pl['fk_product'],
						'qty' => (float) $pl['qty'],
						'fk_prescription' => (int) $ps->id,
						'fk_dispense' => 0,
					);
				}
			}
		}
	}

	llxHeader('', $langs->trans("ClinicPayBillNew"));
	// Page title: without it the page started straight with the patient banner and
	// gave no clue what was being created.
	print load_fiche_titre($langs->trans("ClinicPayBillNew"), '', 'fa-credit-card');
	print patient_summary_banner($summary, array(array('label' => $langs->trans('ClinicPayBillTab'), 'url' => dol_buildpath('/clinicpay/patient_tab.php', 1).'?tab=bills&id='.((int) $fkPatientParam))), 'clinicpay');

	print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="add">';
	print '<input type="hidden" name="fk_patient" value="'.((int) $fkPatientParam).'">';
	if ($fkMedrecordParam > 0) {
		print '<input type="hidden" name="fk_medrecord" value="'.((int) $fkMedrecordParam).'">';
	}

	print '<table class="border centpercent tableforfieldcreate">';
	print '<tr><td class="titlefieldcreate">'.$langs->trans("ClinicPayPatient").'</td><td>'.dol_escape_htmltag($summary['name']).'</td></tr>';
	print '<tr><td class="titlefieldcreate">'.$langs->trans("ClinicPayBillNote").'</td><td><textarea class="flat" name="note" rows="2" cols="60"></textarea></td></tr>';
	print '</table>';

	if (!empty($dispensePrefill)) {
		if ($fkDispenseParam > 0) {
			print '<div class="info">'.$langs->trans("ClinicPayBillFromDispense").': '.dol_escape_htmltag($fromDispenseRef);
			if ($fromPrescRef) {
				print ' ('.$langs->trans("PharmacyPrescription").' '.dol_escape_htmltag($fromPrescRef).')';
			}
			print ' — <a href="'.dol_buildpath('/pharmacy/card.php', 1).'?id='.$fkDispenseParam.'">'.$langs->trans("ClinicPayViewDispense").'</a></div>';
		} else {
			print '<div class="info">'.$langs->trans("ClinicPayBillFromPrescription").': '.dol_escape_htmltag($fromPrescRef);
			print ' — <a href="'.dol_buildpath('/prescription/card.php', 1).'?id='.$fkPrescriptionParam.'">'.$langs->trans("ClinicPayViewPrescription").'</a></div>';
		}
	}

	$editorRows = array();
	if (!empty($dispensePrefill)) {
		$editorRows = $dispensePrefill;
	} else {
		for ($i = 0; $i < 8; $i++) {
			$editorRows[] = array('fk_product' => 0, 'qty' => 0, 'fk_prescription' => 0, 'fk_dispense' => 0);
		}
	}

	print '<div class="paddingbottom"><input type="text" id="bill_product_filter" class="minwidth300" placeholder="'.dol_escape_htmltag($langs->trans('ClinicPayBillProductFilter')).'"></div>';
	print '<div class="div-table-responsive-no-min"><table id="bill_editor" class="noborder centpercent">';
	print '<tr class="liste_titre"><th>#</th><th>'.$langs->trans("ClinicPayBillProduct").'</th><th class="right" style="width:120px">'.$langs->trans("ClinicPayBillQty").'</th><th class="right" style="width:120px">'.$langs->trans("ClinicPayBillSubtotal").'</th></tr>';
	foreach ($editorRows as $i => $row) {
		$sel = (int) $row['fk_product'];
		$qtyVal = !empty($row['qty']) ? price2num((float) $row['qty'], 'MS') : '';
		print '<tr class="oddeven">';
		print '<td>'.($i + 1).'</td>';
		print '<td>';
		print '<select class="flat minwidth300" name="fk_product['.$i.']">';
		print '<option value="0">-</option>';
		foreach ($products as $pid => $plabel) {
			$selAttr = ($pid == $sel) ? ' selected' : '';
			print '<option value="'.$pid.'"'.$selAttr.'>'.dol_escape_htmltag($plabel).'</option>';
		}
		print '</select>';
		if (!empty($row['fk_prescription'])) {
			print '<input type="hidden" name="fk_prescription['.$i.']" value="'.((int) $row['fk_prescription']).'">';
		}
		if (!empty($row['fk_dispense'])) {
			print '<input type="hidden" name="fk_dispense['.$i.']" value="'.((int) $row['fk_dispense']).'">';
		}
		print '</td>';
		print '<td class="right"><input class="flat right width75" style="width:70px;" type="text" name="qty['.$i.']" value="'.$qtyVal.'"></td>';
		print '<td class="right subtotal-cell">'.price(0).'</td>';
		print '</tr>';
	}
	print '</table></div>';
	print '<div class="right paddingbottom"><strong>'.$langs->trans("ClinicPayBillEditorTotal").'：<span id="bill_editor_total">'.price(0).'</span></strong></div>';

	print '<div class="opacitymedium paddingbottom">'.$langs->trans("ClinicPayBillSnapshotNote").'</div>';

	// Apply-bundle picker: only for a plain editor (no dispense/prescription prefill)
	if (empty($dispensePrefill) && !empty($products)) {
		$bundles = ClinicPayBundle::listAll($db, 1);
		if (!empty($bundles)) {
			$bundleOpts = array('' => $langs->trans("ClinicPayBundleSelect"));
			foreach ($bundles as $b) {
				$bundleOpts[$b['id']] = $b['name'].' ('.((int) $b['nlines']).')';
			}
			print '<div style="margin-bottom:6px;">';
			print '<span style="font-weight:bold;">'.$langs->trans("ClinicPayBundle").'：</span> ';
			print $form->selectarray('bundle_pick', $bundleOpts, '', 1, 0, 0, '', 0, 0, 0, '', 'minwidth250 inline-block');
			print '</div>';
			print '<script type="text/javascript">
$(function() {
	var bundleUrl = '.json_encode(dol_buildpath('/clinicpay/ajax/bundle.php', 1)).';
	function renumberEditor() {
		$("#bill_editor tr.oddeven").each(function(i) {
			var tr = $(this);
			tr.find("select[name^=\'fk_product\']").attr("name", "fk_product["+i+"]");
			tr.find("input[name^=\'qty\']").attr("name", "qty["+i+"]");
		});
	}
	$("#bundle_pick").on("change", function() {
		var bid = $(this).val();
		if (!bid) { return; }
		$.getJSON(bundleUrl, { id: bid }, function(data) {
			if (!data || data.error) { return; }
			var rows = $("#bill_editor tr.oddeven");
			$.each(data.lines, function(i, l) {
				var tr = rows.eq(i);
				if (tr.length === 0) {
					tr = rows.last().clone(true);
					tr.find("input[name^=\'fk_prescription\']").remove();
					tr.find("input[name^=\'fk_dispense\']").remove();
					$("#bill_editor tr.oddeven").last().after(tr);
					rows = $("#bill_editor tr.oddeven");
				}
				tr.find("input[name^=\'fk_prescription\']").remove();
				tr.find("input[name^=\'fk_dispense\']").remove();
			tr.find("select[name^=\'fk_product\']").val(l.fk_product).trigger(\'change\');
			tr.find("input[name^=\'qty\']").val(l.qty).trigger(\'change\');
			});
			renumberEditor();
		});
	});
});
</script>';
		}
	}

	// Live subtotal + product filter for the charge editor. recompute() is
	// global so the bundle picker above can call it after filling rows.
	print '<script type="text/javascript">
	function clinicpayRecomputeBill(tr) {
		var pid = parseInt(tr.find("select[name^=\'fk_product\']").val(), 10) || 0;
		var qty = parseFloat(tr.find("input[name^=\'qty\']").val()) || 0;
		var sub = (clinicpayProductPrices[pid] ? clinicpayProductPrices[pid] : 0) * qty;
		tr.find(".subtotal-cell").text(sub.toFixed(2));
		var total = 0;
		$("#bill_editor tr.oddeven").each(function() {
			var p = parseInt($(this).find("select[name^=\'fk_product\']").val(), 10) || 0;
			var q = parseFloat($(this).find("input[name^=\'qty\']").val()) || 0;
			total += (clinicpayProductPrices[p] ? clinicpayProductPrices[p] : 0) * q;
		});
		$("#bill_editor_total").text(total.toFixed(2));
	}
	$(function() {
		window.clinicpayProductPrices = '.json_encode($productPrices).';
		$("#bill_editor").on("change keyup", "select[name^=\'fk_product\'], input[name^=\'qty\']", function() {
			clinicpayRecomputeBill($(this).closest("tr"));
		});
		$("#bill_product_filter").on("keyup", function() {
			var v = $(this).val().toLowerCase();
			$("#bill_editor select[name^=\'fk_product\']").each(function() {
				$(this).find("option").each(function() {
					if ($(this).val() === "0") { $(this).show(); return; }
					$(this).toggle($(this).text().toLowerCase().indexOf(v) > -1);
				});
			});
		});
		$("#bill_editor tr.oddeven").each(function() { clinicpayRecomputeBill($(this)); });
	});
	</script>';

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
	$fkPresc = GETPOST('fk_prescription', 'array');
	$fkDisp = GETPOST('fk_dispense', 'array');
	foreach ($fkProducts as $i => $pid) {
		$pid = (int) $pid;
		$qty = isset($qtys[$i]) ? (float) $qtys[$i] : 0;
		if ($pid > 0 && $qty > 0) {
			$line = array('fk_product' => $pid, 'qty' => $qty);
			if (isset($fkPresc[$i]) && (int) $fkPresc[$i] > 0) {
				$line['fk_prescription'] = (int) $fkPresc[$i];
			}
			if (isset($fkDisp[$i]) && (int) $fkDisp[$i] > 0) {
				$line['fk_dispense'] = (int) $fkDisp[$i];
			}
			$lines[] = $line;
		}
	}
	$data = array('fk_patient' => GETPOSTINT('fk_patient'), 'fk_medrecord' => GETPOSTINT('fk_medrecord'), 'note' => (string) GETPOST('note', 'restricthtml'), 'lines' => $lines);
	$result = $dao->create($user, $data);
	if ($result > 0) {
		setEventMessages($langs->trans("RecordSaved").' '.$dao->ref, null, 'mesgs');
		header('Location: '.$_SERVER["PHP_SELF"].'?id='.$dao->id);
		exit;
	}
	clinicpay_redirect_error($dao->error, $_SERVER["PHP_SELF"].'?action=create&fk_patient='.((int) $data['fk_patient']).($data['fk_medrecord'] > 0 ? '&fk_medrecord='.((int) $data['fk_medrecord']) : '').'&token='.newToken());
}

// ------------------------------------------------------------ confirm charge
if ($action == 'confirm' && $id > 0 && $token != '') {
	if (!$user->hasRight('clinicpay', 'pay')) {
		accessforbidden();
	}
	$channel = GETPOST('channel', 'alpha');
	$channelRef = (string) GETPOST('channel_ref', 'alpha');
	$cardAmount = GETPOST('card_amount', 'alpha');
	$channel2 = GETPOST('channel2', 'alpha');
	$channel2Ref = (string) GETPOST('channel2_ref', 'alpha');
	$result = $dao->confirm($user, $channel, $channelRef, $cardAmount === '' ? null : $cardAmount, $channel2, $channel2Ref);
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

// ------------------------------------------------------------ refund: fully-card bill (validate)
// A bill settled entirely by prepaid card has no native invoice, so the
// normal credit-note refund does not apply: re-credit the card balance and
// mark the bill refunded.
if ($action == 'refund_card' && $confirm == 'yes' && $id > 0 && $token != '') {
	if (!$user->hasRight('clinicpay', 'validate')) {
		accessforbidden();
	}
	$result = $dao->refundCard($user);
	if ($result > 0) {
		setEventMessages($langs->trans("ClinicPayRefundCardDone").' '.$dao->ref, null, 'mesgs');
	} else {
		clinicpay_redirect_error($dao->error, $_SERVER["PHP_SELF"].'?id='.$id);
	}
	header('Location: '.$_SERVER["PHP_SELF"].'?id='.$id);
	exit;
}

// ------------------------------------------------------------ tax invoice number (registry only)
if ($action == 'set_fapiao' && $id > 0 && $token != '') {
	if (!$user->hasRight('clinicpay', 'write')) {
		accessforbidden();
	}
	$fapiaoNo = (string) GETPOST('fapiao_no', 'alphanohtml');
	$result = $dao->setFapiaoNo($user, $fapiaoNo);
	if ($result > 0) {
		setEventMessages($fapiaoNo === '' ? $langs->trans("ClinicPayFapiaoCleared") : $langs->trans("ClinicPayFapiaoSaved", $fapiaoNo), null, 'mesgs');
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
print '<td>'.$langs->trans("ClinicPayBillPayDate").'</td><td>'.($dao->date_pay ? dol_print_date($dao->date_pay, '%Y-%m-%d %H:%M') : '').'</td></tr>';
if (price2num((float) $dao->card_amount, 'MT') > 0.005) {
	print '<tr><td>'.$langs->trans("ClinicPayBillCardAmount").'</td><td colspan="3">'.price($dao->card_amount);
	if ((int) $dao->fk_invoice <= 0) {
		print ' <span class="opacitymedium">('.$langs->trans("ClinicPayBillCardOnly").')</span>';
	}
	print '</td></tr>';
	if ($dao->channel2 !== '') {
		print '<tr><td>'.$langs->trans("ClinicPayBillChannel2").'</td><td colspan="3">'.dol_escape_htmltag(clinicpay_channel_label($dao->channel2));
		if ($dao->channel2_ref !== '') {
			print ' <span class="opacitymedium">('.dol_escape_htmltag($dao->channel2_ref).')</span>';
		}
		print '</td></tr>';
	}
}
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
print '<tr><td>'.$langs->trans("DateCreation").'</td><td colspan="3">'.dol_print_date($dao->date_creation, '%Y-%m-%d %H:%M').'</td></tr>';
print '<tr><td>'.$langs->trans("ClinicPayFapiaoNo").'</td><td colspan="3">';
if ((string) $dao->fapiao_no !== '') {
	print '<span class="opacitymedium">'.$langs->trans("ClinicPayFapiaoRegistered").'</span> '.dol_escape_htmltag($dao->fapiao_no);
} else {
	print '<span class="opacitymedium">'.$langs->trans("ClinicPayFapiaoNone").'</span>';
}
print '</td></tr>';
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
if (!$isDraft && $user->hasRight('clinicpay', 'write')) {
	// Registry only: the tax invoice itself is issued in the tax system.
	print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'" class="inlineform">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="set_fapiao">';
	print '<input type="hidden" name="id" value="'.((int) $dao->id).'">';
	print '<span class="opacitymedium">'.$langs->trans("ClinicPayFapiaoNo").'</span> ';
	print '<input class="flat minwidth200" type="text" name="fapiao_no" value="'.dol_escape_htmltag((string) $dao->fapiao_no).'" placeholder="'.dol_escape_htmltag($langs->trans("ClinicPayFapiaoPlaceholder")).'">';
	print '<button type="submit" class="butAction smallpaddingimp">'.$langs->trans("ClinicPayFapiaoSave").'</button>';
	print '</form>';
}
$isFullyCard = (price2num((float) $dao->card_amount, 'MT') > 0.005 && (int) $dao->fk_invoice <= 0);
if ($isPaid) {
	if ($isFullyCard && $user->hasRight('clinicpay', 'validate')) {
		print dolGetButtonAction($langs->trans("ClinicPayRefundCard"), '', 'delete', $_SERVER["PHP_SELF"].'?id='.$dao->id.'&action=refund_card&token='.newToken(), '', 1);
	} elseif (!$isFullyCard && $user->hasRight('clinicpay', 'write')) {
		print dolGetButtonAction($langs->trans("ClinicPayRefundStart"), '', 'delete', $_SERVER["PHP_SELF"].'?id='.$dao->id.'&action=refund&token='.newToken(), '', 1);
	}
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
	$cardBalance = ServiceCard::availableValue($db, $dao->fk_patient);
	$cardBalance = price2num($cardBalance, 'MT');
	$cardMax = price2num(min((float) $dao->amount_total, $cardBalance), 'MT');
	$cardDisabled = $cardBalance <= 0.005;
	print load_fiche_titre($langs->trans("ClinicPayBillConfirmTitle"), '', 'fa-money-check-alt');
	print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'" id="bill_confirm_form">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="confirm">';
	print '<input type="hidden" name="id" value="'.((int) $dao->id).'">';
	print '<table class="border centpercent tableforfield">';
	print '<tr><td class="titlefield fieldrequired">'.$langs->trans("ClinicPayBillChannel").'</td><td>';
	print '<select class="flat" name="channel" id="bill_channel">';
	print '<option value="CASH">'.dol_escape_htmltag(clinicpay_channel_label(CLINICPAY_CHANNEL_CASH)).'</option>';
	print '<option value="SCAN">'.dol_escape_htmltag(clinicpay_channel_label(CLINICPAY_CHANNEL_SCAN)).'</option>';
	print '<option value="CARD">'.dol_escape_htmltag(clinicpay_channel_label(CLINICPAY_CHANNEL_CARD)).'</option>';
	print '</select></td></tr>';
	print '<tr id="row_channel_ref"><td>'.$langs->trans("ClinicPayBillChannelRef").'</td><td><input class="flat minwidth200" type="text" name="channel_ref" value=""> <span class="opacitymedium">'.$langs->trans("ClinicPayChannelRefHint").'</span></td></tr>';

	// Card block (shown only when channel = CARD)
	print '<tr class="cardrow" style="display:none"><td>'.$langs->trans("ClinicPayCardPayBalance", price($cardBalance)).'</td><td>';
	if ($cardDisabled) {
		print '<span class="opacitymedium">'.$langs->trans("ClinicPayCardPayHint").'</span>';
	} else {
		print '<span class="opacitymedium">'.dol_escape_htmltag($langs->trans("ClinicPayCardPayHint")).'</span>';
	}
	print '</td></tr>';
	print '<tr class="cardrow" style="display:none"><td class="fieldrequired">'.$langs->trans("ClinicPayBillCardAmount").'</td><td><input class="flat" type="text" name="card_amount" id="bill_card_amount" value="'.$cardMax.'"'.($cardDisabled ? ' disabled' : '').'> <span class="opacitymedium">'.dol_escape_htmltag($langs->trans("ClinicPayBillAmount").' '.price($dao->amount_total)).'</span></td></tr>';

	// Secondary channel (shown when card portion < total)
	print '<tr class="c2row" style="display:none"><td class="fieldrequired">'.$langs->trans("ClinicPayBillChannel2").'</td><td>';
	print '<select class="flat" name="channel2" id="bill_channel2">';
	print '<option value="">-</option>';
	print '<option value="CASH">'.dol_escape_htmltag(clinicpay_channel_label(CLINICPAY_CHANNEL_CASH)).'</option>';
	print '<option value="SCAN">'.dol_escape_htmltag(clinicpay_channel_label(CLINICPAY_CHANNEL_SCAN)).'</option>';
	print '</select></td></tr>';
	print '<tr class="c2row" style="display:none" id="row_channel2_ref"><td>'.$langs->trans("ClinicPayBillChannelRef").'</td><td><input class="flat minwidth200" type="text" name="channel2_ref" value=""> <span class="opacitymedium">'.$langs->trans("ClinicPayChannelRefHint").'</span></td></tr>';

	print '</table>';
	print '<div class="center paddingtop"><input type="submit" class="button" value="'.dol_escape_htmltag($langs->trans("ClinicPayBillConfirmTitle")).'"></div>';
	print '</form>';
	print '<script type="text/javascript">
$(function() {
	var total = '.price2num((float) $dao->amount_total, 'MT').';
	var balance = '.$cardBalance.';
	function clinicpayRefreshConfirm() {
		var ch = $("#bill_channel").val();
		var isCard = (ch === "CARD");
		$("#row_channel_ref").toggle(!isCard);
		$(".cardrow").toggle(isCard);
		if (!isCard) { $(".c2row").hide(); return; }
		var ca = parseFloat($("#bill_card_amount").val()) || 0;
		var remainder = total - ca;
		$(".c2row").toggle(remainder > 0.005);
	}
	$("#bill_channel").on("change", clinicpayRefreshConfirm);
	$("#bill_card_amount").on("keyup change", clinicpayRefreshConfirm);
	$("#bill_confirm_form").on("submit", function() {
		var ch = $("#bill_channel").val();
		if (ch !== "CARD") { return true; }
		var ca = parseFloat($("#bill_card_amount").val()) || 0;
		if (ca <= 0 || ca > balance + 0.005) { alert("'.dol_escape_js($langs->trans("ClinicPayCardPayShortage")).'"); return false; }
		var remainder = total - ca;
		if (remainder > 0.005) {
			var c2 = $("#bill_channel2").val();
			if (c2 === "") { alert("'.dol_escape_js($langs->trans("ClinicPayErrChannel2")).'"); return false; }
			if (c2 === "SCAN" && $.trim($("#bill_channel2_ref").val()) === "") { alert("'.dol_escape_js($langs->trans("ClinicPayErrRefRequired")).'"); return false; }
		}
		return true;
	});
	clinicpayRefreshConfirm();
});
</script>';
}

// Refund forms (reason input for step 1; formconfirm for step 2)
if ($action == 'refund' && $isPaid && !$isFullyCard && ($user->hasRight('clinicpay', 'write') || $user->hasRight('clinicpay', 'validate'))) {
	$draftId = $dao->findRefundDraft();
	if ($draftId <= 0 && $user->hasRight('clinicpay', 'write')) {
		print $form->formconfirm($_SERVER["PHP_SELF"].'?id='.$dao->id, $langs->trans("ClinicPayRefundStart"), $langs->trans("ClinicPayRefundReasonAsk"), 'refund_draft', array(array('type' => 'text', 'name' => 'refund_reason', 'label' => $langs->trans("ClinicPayRefundReason"), 'value' => '', 'size' => 60)), 0, 1);
	} elseif ($draftId > 0 && $user->hasRight('clinicpay', 'validate')) {
		print $form->formconfirm($_SERVER["PHP_SELF"].'?id='.$dao->id, $langs->trans("ClinicPayRefundExecute"), $langs->trans("ClinicPayRefundConfirmAsk"), 'confirm_refund', array(), 0, 1);
	}
}
if ($action == 'refund_card' && $isPaid && $isFullyCard && $user->hasRight('clinicpay', 'validate')) {
	print $form->formconfirm($_SERVER["PHP_SELF"].'?id='.$dao->id, $langs->trans("ClinicPayRefundCard"), $langs->trans("ClinicPayRefundCardConfirmAsk", price($dao->card_amount)), 'refund_card', array(), 0, 1);
}

llxFooter();
$db->close();
