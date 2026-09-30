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
 * \file    htdocs/custom/clinicpay/card.php
 * \ingroup clinicpay
 * \brief   Prepaid card page (spec §3.4 / §3.6): sell form (action=create),
 *          card fiche with progress + log timeline, consume / top-up /
 *          two-step refund. Consuming creates no invoice (already paid).
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
dol_include_once('/clinicpay/class/paybill.class.php');

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

$langs->loadLangs(array("patient@patient", "clinicpay@clinicpay"));

$action = GETPOST('action', 'aZ09');
$id = GETPOSTINT('id');
$fkPatient = GETPOSTINT('fk_patient');
$token = GETPOST('token', 'alpha');

/**
 * Flash a message on the card page (or the sell form) and redirect.
 *
 * @param	string	$errKeyOrText	Error key or message ('' = success style)
 * @param	string	$url			Redirect target
 * @return	void
 */
function clinicpay_card_redirect($errKeyOrText, $url)
{
	if ($errKeyOrText !== '') {
		setEventMessages($errKeyOrText, array(), 'errors');
	} else {
		setEventMessages('ClinicPayOk', array());
	}
	header('Location: '.$url);
	exit;
}

// ---------------------------------------------------------------- actions

if ($action === 'sell_confirm') {
	if (empty($user->rights->clinicpay->write) || empty($user->rights->clinicpay->pay) || $token === '') {
		accessforbidden();
	}
	$data = array(
		'fk_patient' => GETPOSTINT('fk_patient'),
		'card_type' => GETPOST('card_type', 'aZ09'),
		'fk_product' => GETPOSTINT('fk_product'),
		'count' => GETPOSTINT('count'),
		'value' => price2num(GETPOST('value', 'alpha'), 'MT'),
		'date_end' => trim(GETPOST('date_end', 'alpha')),
		'channel' => GETPOST('channel', 'aZ09'),
		'channel_ref' => trim(GETPOST('channel_ref', 'alpha')),
		'note' => GETPOST('note', 'alphanohtml'),
		'label' => '',
	);
	$langs->load('clinicpay@clinicpay');
	if ($data['card_type'] === CLINICPAY_CARD_COUNT) {
		$data['label'] = $langs->trans('ClinicPayCardTypeCount').' '.$data['count'];
	} else {
		$data['label'] = $langs->trans('ClinicPayCardTypeValue').' '.price($data['value']);
	}
	$dao = new ServiceCard($db);
	$rc = $dao->sell($user, $data);
	if ($rc > 0) {
		setEventMessages($langs->trans('ClinicPayOkSell').' '.$dao->ref, array());
		header('Location: '.$_SERVER["PHP_SELF"].'?id='.$dao->id.'&token='.newToken());
		exit;
	}
	$msg = ($dao->error !== '' && preg_match('/^ClinicPayErr/', $dao->error)) ? $langs->trans($dao->error) : $dao->error;
	clinicpay_card_redirect($msg, $_SERVER["PHP_SELF"].'?action=create&fk_patient='.((int) $data['fk_patient']).'&token='.newToken());
}

if ($action === 'consume' && $id > 0) {
	if (empty($user->rights->clinicpay->consume) || $token === '') {
		accessforbidden();
	}
	$dao = new ServiceCard($db);
	if ($dao->fetch($id) <= 0) {
		accessforbidden();
	}
	$amount = price2num(GETPOST('consume_value', 'alpha'), 'MT');
	$rc = $dao->consume($user, $dao->card_type === CLINICPAY_CARD_VALUE ? $amount : 0.0, GETPOST('consume_note', 'alphanohtml'));
	if ($rc > 0) {
		setEventMessages($langs->trans('ClinicPayOkConsume'), array());
	} else {
		$msg = ($dao->error !== '' && preg_match('/^ClinicPayErr/', $dao->error)) ? $langs->trans($dao->error) : $dao->error;
		setEventMessages($msg, array(), 'errors');
	}
	header('Location: '.$_SERVER["PHP_SELF"].'?id='.$id.'&token='.newToken());
	exit;
}

if ($action === 'charge_confirm' && $id > 0) {
	if (empty($user->rights->clinicpay->write) || empty($user->rights->clinicpay->pay) || $token === '') {
		accessforbidden();
	}
	$dao = new ServiceCard($db);
	if ($dao->fetch($id) <= 0) {
		accessforbidden();
	}
	$data = array(
		'count' => GETPOSTINT('count'),
		'value' => price2num(GETPOST('value', 'alpha'), 'MT'),
		'fk_product' => GETPOSTINT('fk_product'),
		'channel' => GETPOST('channel', 'aZ09'),
		'channel_ref' => trim(GETPOST('channel_ref', 'alpha')),
		'note' => GETPOST('note', 'alphanohtml'),
		'label' => '',
	);
	$langs->load('clinicpay@clinicpay');
	if ($dao->card_type === CLINICPAY_CARD_COUNT) {
		$data['label'] = $langs->trans('ClinicPayLogCharge').' +'.$data['count'];
	} else {
		$data['label'] = $langs->trans('ClinicPayLogCharge').' '.price($data['value']);
	}
	$rc = $dao->charge($user, $data);
	if ($rc > 0) {
		setEventMessages($langs->trans('ClinicPayOkCharge'), array());
	} else {
		$msg = ($dao->error !== '' && preg_match('/^ClinicPayErr/', $dao->error)) ? $langs->trans($dao->error) : $dao->error;
		setEventMessages($msg, array(), 'errors');
	}
	header('Location: '.$_SERVER["PHP_SELF"].'?id='.$id.'&token='.newToken());
	exit;
}

// ---------------------------------------------------------------- view

if ($action !== 'create') {
	if ($id <= 0 || !$user->hasRight('clinicpay', 'read')) {
		accessforbidden();
	}
	$object = new ServiceCard($db);
	if ($object->fetch($id) <= 0) {
		dol_print_error($db, $langs->trans("ErrorRecordNotFound"));
		exit;
	}
	$form = new Form($db);
	$grace = (int) getDolGlobalInt('CLINICPAY_CARD_GRACE_DAYS');
	$expired = $object->isExpired($grace);
	$canConsume = !empty($user->rights->clinicpay->consume) && (int) $object->status === CLINICPAY_CARD_VALID && !$expired;
	$canPay = !empty($user->rights->clinicpay->write) && !empty($user->rights->clinicpay->pay);

	// lazy pending refund info (only for refundable cards)
	$pendingRefund = false;
	if (in_array((int) $object->status, array(CLINICPAY_CARD_VALID, CLINICPAY_CARD_USED), true)) {
		$pendingRefund = $object->hasPendingRefund();
	}

	if ($action === 'refund_create' && !empty($user->rights->clinicpay->write) && $token !== '' && GETPOST('confirm', 'aZ09') === 'yes') {
		$rc = $object->createRefund($user, GETPOST('refund_reason', 'alphanohtml'));
		if ($rc > 0) {
			setEventMessages($langs->trans('ClinicPayRefundDraftCreated'), array());
		} else {
			$msg = ($object->error !== '' && preg_match('/^ClinicPayErr/', $object->error)) ? $langs->trans($object->error) : $object->error;
			setEventMessages($msg, array(), 'errors');
		}
		header('Location: '.$_SERVER["PHP_SELF"].'?id='.$id.'&token='.newToken());
		exit;
	}
	if ($action === 'refund_execute' && !empty($user->rights->clinicpay->validate) && $token !== '' && GETPOST('confirm', 'aZ09') === 'yes') {
		$rc = $object->executeRefund($user);
		if ($rc > 0) {
			setEventMessages($langs->trans('ClinicPayCardRefundDone'), array());
		} else {
			$msg = ($object->error !== '' && preg_match('/^ClinicPayErr/', $object->error)) ? $langs->trans($object->error) : $object->error;
			setEventMessages($msg, array(), 'errors');
		}
		header('Location: '.$_SERVER["PHP_SELF"].'?id='.$id.'&token='.newToken());
		exit;
	}

	llxHeader('', $langs->trans("ClinicPayCardTab"));

	// formconfirms (after llxHeader: formconfirm prints HTML, printing it
	// before the header used to trigger "headers already sent" warnings)
	if ($action === 'refund' && !empty($user->rights->clinicpay->write) && $token !== '') {
		$restLabel = '';
		if ($object->card_type === CLINICPAY_CARD_COUNT) {
			$restLabel = $langs->trans('ClinicPayCardRestCount', max(0, (int) $object->total_count - (int) $object->used_count));
		} else {
			$restLabel = $langs->trans('ClinicPayCardRestValue', price2num(max(0, (float) $object->total_value - (float) $object->used_value), 'MT'));
		}
		print $form->formconfirm($_SERVER["PHP_SELF"].'?id='.$object->id.'&token='.newToken(), $langs->trans("ClinicPayCardRefundStart"), $langs->trans("ClinicPayCardRefundReasonAsk", $restLabel), 'refund_create', array(array('type' => 'textarea', 'name' => 'refund_reason', 'label' => $langs->trans("ClinicPayCardRefundReason"), 'value' => '', 'moreattr' => 'rows="3"')), 0, 1, 280, 600);
	}
	if ($action === 'refund_exec' && !empty($user->rights->clinicpay->validate) && $token !== '') {
		print $form->formconfirm($_SERVER["PHP_SELF"].'?id='.$object->id.'&token='.newToken(), $langs->trans("ClinicPayCardRefundExecute"), $langs->trans("ClinicPayRefundConfirmAsk"), 'refund_execute', '', 0, 1);
	}

	print patient_summary_banner(patient_get_summary($db, $object->fk_patient), array(), 'clinicpay');

	print dol_get_fiche_head(array(), '', $langs->trans("ClinicPayCardTab").' '.$object->ref, -1, 'fa-credit-card');

	// actions
	print '<div class="tabsAction">';
	if ($canConsume) {
		print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'" style="display:inline;">';
		print '<input type="hidden" name="token" value="'.newToken().'">';
		print '<input type="hidden" name="action" value="consume">';
		print '<input type="hidden" name="id" value="'.(int) $object->id.'">';
		if ($object->card_type === CLINICPAY_CARD_VALUE) {
			print '<input type="text" name="consume_value" class="width75" placeholder="'.$langs->trans("ClinicPayCardValue").'"> ';
		}
		print '<input type="text" name="consume_note" class="width200" placeholder="'.$langs->trans("Note").'"> ';
		print '<input type="submit" class="button" name="consume_btn" value="'.dol_escape_htmltag($object->card_type === CLINICPAY_CARD_COUNT ? $langs->trans("ClinicPayCardConsumeOnce") : $langs->trans("ClinicPayCardConsume")).'">';
		print '</form> ';
	}
	if ($canPay && in_array((int) $object->status, array(CLINICPAY_CARD_VALID, CLINICPAY_CARD_USED, CLINICPAY_CARD_EXPIRED), true)) {
		print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'" style="display:inline;">';
		print '<input type="hidden" name="token" value="'.newToken().'">';
		print '<input type="hidden" name="action" value="charge_confirm">';
		print '<input type="hidden" name="id" value="'.(int) $object->id.'">';
		if ($object->card_type === CLINICPAY_CARD_COUNT) {
			print '<input type="text" name="count" class="width50" placeholder="'.$langs->trans("ClinicPayCardCount").'"> ';
		} else {
			print '<input type="text" name="value" class="width75" placeholder="'.$langs->trans("ClinicPayCardValue").'"> ';
		}
		print '<select name="channel" class="maxwidth100"><option value="CASH">'.$langs->trans("ClinicPayChannelCash").'</option><option value="SCAN">'.$langs->trans("ClinicPayChannelScan").'</option></select> ';
		print '<input type="text" name="channel_ref" class="width150" placeholder="'.$langs->trans("ClinicPayChannelRef").'"> ';
		print '<input type="submit" class="button" name="charge_btn" value="'.dol_escape_htmltag($langs->trans("ClinicPayCardCharge")).'">';
		print '</form> ';
	}
	if (!empty($user->rights->clinicpay->write) && in_array((int) $object->status, array(CLINICPAY_CARD_VALID, CLINICPAY_CARD_USED), true)) {
		if ($pendingRefund) {
			if (!empty($user->rights->clinicpay->validate)) {
				print dolGetButtonAction($langs->trans("ClinicPayCardRefundExecute"), '', 'danger', $_SERVER["PHP_SELF"].'?id='.$object->id.'&action=refund_exec&token='.newToken(), '', 1);
			} else {
				print '<span class="opacitymedium">'.$langs->trans("ClinicPayCardRefundPending").'</span> ';
			}
		} else {
			print dolGetButtonAction($langs->trans("ClinicPayCardRefundStart"), '', 'delete', $_SERVER["PHP_SELF"].'?id='.$object->id.'&action=refund&token='.newToken(), '', 1);
		}
	}
	print '</div>';

	// fiche
	print '<table class="border tableforfield centpercent">';
	print '<tr><td class="titlefield">'.$langs->trans("ClinicPayCardNo").'</td><td>'.dol_escape_htmltag($object->ref).'</td>';
	print '<td class="titlefield">'.$langs->trans("ClinicPayCardStatus").'</td><td>';
	print clinicpay_card_status_badge($object->status);
	if ((int) $object->status === CLINICPAY_CARD_VALID && $expired) {
		print ' <span class="badge badge-status9">'.$langs->trans("ClinicPayCardExpired").($grace > 0 ? ' (+'.$grace.'d)' : '').'</span>';
	}
	print '</td></tr>';
	print '<tr><td>'.$langs->trans("ClinicPayCardType").'</td><td>'.dol_escape_htmltag(clinicpay_card_type_label($object->card_type)).'</td>';
	print '<td>'.$langs->trans("ClinicPayCardDateEnd").'</td><td>'.($object->date_end ? dol_print_date($db->jdate($object->date_end), 'day') : $langs->trans("ClinicPayCardNoEnd")).'</td></tr>';
	if ($object->card_type === CLINICPAY_CARD_COUNT) {
		$rest = max(0, (int) $object->total_count - (int) $object->used_count);
		$pct = (int) $object->total_count > 0 ? round(100 * $object->used_count / $object->total_count) : 0;
		print '<tr><td>'.$langs->trans("ClinicPayCardProgress").'</td><td colspan="3">';
		print '<div class="progress" style="background:#eee;height:14px;border-radius:4px;max-width:420px;display:inline-block;vertical-align:middle;"><div style="background:#2e7d32;height:14px;width:'.$pct.'%;border-radius:4px;"></div></div> ';
		print (int) $object->used_count.' / '.(int) $object->total_count.' &nbsp; '.$langs->trans("ClinicPayCardRemaining").': <strong>'.$rest.'</strong>';
		print '</td></tr>';
	} else {
		$rest = max(0, (float) $object->total_value - (float) $object->used_value);
		$pct = (float) $object->total_value > 0 ? round(100 * $object->used_value / $object->total_value) : 0;
		print '<tr><td>'.$langs->trans("ClinicPayCardProgress").'</td><td colspan="3">';
		print '<div class="progress" style="background:#eee;height:14px;border-radius:4px;max-width:420px;display:inline-block;vertical-align:middle;"><div style="background:#2e7d32;height:14px;width:'.$pct.'%;border-radius:4px;"></div></div> ';
		print price($object->used_value).' / '.price($object->total_value).' &nbsp; '.$langs->trans("ClinicPayCardRemaining").': <strong>'.price($rest).'</strong>';
		print '</td></tr>';
	}
	if ($object->note) {
		print '<tr><td>'.$langs->trans("Note").'</td><td colspan="3">'.dol_escape_htmltag($object->note).'</td></tr>';
	}
	print '</table>';

	print dol_get_fiche_end();

	// log timeline
	$logsRes = $object->searchLogs();
	print load_fiche_titre($langs->trans("ClinicPayCardLogs"), '', 'fa-list');
	print '<div class="div-table-responsive">';
	print '<table class="tagtable liste centpercent">'."\n";
	print '<tr class="liste_titre"><th class="center">'.$langs->trans("Date").'</th><th>'.$langs->trans("Action").'</th><th class="right">'.$langs->trans("ClinicPayCardCount").'</th><th class="right">'.$langs->trans("ClinicPayCardValue").'</th><th>'.$langs->trans("User").'</th><th>'.$langs->trans("Note").'</th><th>'.$langs->trans("ClinicPayRef").'</th></tr>'."\n";
	if ($logsRes === null || $logsRes['total'] === 0) {
		print '<tr><td colspan="7"><span class="opacitymedium">'.$langs->trans("NoRecordFound").'</span></td></tr>';
	} else {
		$logLabels = array(
			CLINICPAY_LOG_CREATE => 'ClinicPayLogCreate',
			CLINICPAY_LOG_CHARGE => 'ClinicPayLogCharge',
			CLINICPAY_LOG_CONSUME => 'ClinicPayLogConsume',
			CLINICPAY_LOG_REFUND => 'ClinicPayLogRefund',
			CLINICPAY_LOG_EXPIRE => 'ClinicPayLogExpire',
		);
		$userNames = array();
		foreach ($logsRes['rows'] as $lg) {
			$uid = (int) $lg->fk_user;
			if ($uid > 0 && !isset($userNames[$uid])) {
				$u = new User($db);
				$userNames[$uid] = $u->fetch($uid) > 0 ? $u->getFullName($langs) : '';
			}
			print '<tr class="oddeven">';
			print '<td class="center">'.dol_print_date($db->jdate($lg->date_creation), 'dayhour').'</td>';
			print '<td>'.dol_escape_htmltag(isset($logLabels[$lg->op]) ? $langs->trans($logLabels[$lg->op]) : $lg->op).'</td>';
			print '<td class="right">'.((int) $lg->count_delta !== 0 ? ((int) $lg->count_delta > 0 ? '+' : '').((int) $lg->count_delta) : '').'</td>';
			print '<td class="right">'.((float) $lg->value_delta != 0 ? ($lg->value_delta > 0 ? '+' : '').price($lg->value_delta) : '').'</td>';
			print '<td>'.dol_escape_htmltag(isset($userNames[$uid]) ? $userNames[$uid] : '').'</td>';
			print '<td>'.dol_escape_htmltag((string) $lg->note).'</td>';
			print '<td>'.($lg->fk_bill !== null ? '<a href="'.dol_buildpath('/clinicpay/bill.php', 1).'?id='.((int) $lg->fk_bill).'">#'.((int) $lg->fk_bill).'</a>' : '').'</td>';
			print '</tr>';
		}
	}
	print '</table>';
	print '</div>';

	llxFooter();
	$db->close();
	exit;
}

// ---------------------------------------------------------------- sell form

if (empty($user->rights->clinicpay->write) || empty($user->rights->clinicpay->pay)) {
	accessforbidden();
}
$form = new Form($db);
llxHeader('', $langs->trans("ClinicPayCardNew"));

if ($fkPatient > 0) {
	print patient_summary_banner(patient_get_summary($db, $fkPatient), array(), 'clinicpay');
}

print load_fiche_titre($langs->trans("ClinicPayCardSell"), '', 'fa-credit-card');

print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="sell_confirm">';
print '<table class="border tableforfield centpercent">';

print '<tr><td class="titlefield fieldrequired">'.$langs->trans("Patient").'</td><td>';
if ($fkPatient > 0) {
	print '<input type="hidden" name="fk_patient" value="'.(int) $fkPatient.'">';
	$ps = patient_get_summary($db, $fkPatient);
	print dol_escape_htmltag($ps ? $ps['name'] : ('#'.$fkPatient));
} else {
	$rows = $db->query("SELECT pp.rowid, s.nom FROM ".$db->prefix()."patient_profile as pp INNER JOIN ".$db->prefix()."societe as s ON s.rowid = pp.fk_soc WHERE pp.entity = ".((int) $conf->entity).$db->order('pp.rowid', 'DESC').$db->plimit(500));
	print '<select name="fk_patient" class="minwidth200">';
	print '<option value=""></option>';
	if ($rows) {
		while ($o = $db->fetch_object($rows)) {
			print '<option value="'.(int) $o->rowid.'">'.dol_escape_htmltag($o->nom).'</option>';
		}
		$db->free($rows);
	}
	print '</select>';
}
print '</td></tr>';

print '<tr><td class="fieldrequired">'.$langs->trans("ClinicPayCardType").'</td><td>';
print '<select name="card_type" id="card_type_sel">';
print '<option value="COUNT">'.dol_escape_htmltag(clinicpay_card_type_label(CLINICPAY_CARD_COUNT)).'</option>';
print '<option value="VALUE">'.dol_escape_htmltag(clinicpay_card_type_label(CLINICPAY_CARD_VALUE)).'</option>';
print '</select></td></tr>';

print '<tr><td>'.$langs->trans("Product").'<br><span class="opacitymedium small">'.$langs->trans("ClinicPayCardProductHint").'</span></td><td>';
print '<select name="fk_product"><option value="0">-</option>';
foreach (clinicpay_product_options($db) as $pid => $ptext) {
	print '<option value="'.(int) $pid.'">'.dol_escape_htmltag($ptext).'</option>';
}
print '</select></td></tr>';

print '<tr><td>'.$langs->trans("ClinicPayCardCount").'<br><span class="opacitymedium small">'.$langs->trans("ClinicPayCardCountHint").'</span></td><td><input type="text" name="count" class="width75" value=""></td></tr>';
print '<tr><td>'.$langs->trans("ClinicPayCardValue").'<br><span class="opacitymedium small">'.$langs->trans("ClinicPayCardValueHint").'</span></td><td><input type="text" name="value" class="width100" value=""></td></tr>';
print '<tr><td>'.$langs->trans("ClinicPayCardDateEndInput").'</td><td><input type="date" name="date_end" value=""></td></tr>';

print '<tr><td class="fieldrequired">'.$langs->trans("ClinicPayChannel").'</td><td>';
print '<select name="channel"><option value="CASH">'.$langs->trans("ClinicPayChannelCash").'</option><option value="SCAN">'.$langs->trans("ClinicPayChannelScan").'</option></select> ';
print '<input type="text" name="channel_ref" class="width200" placeholder="'.dol_escape_htmltag($langs->trans("ClinicPayChannelRef")).'">';
print '</td></tr>';

print '<tr><td>'.$langs->trans("Note").'</td><td><input type="text" name="note" class="minwidth300" value=""></td></tr>';

print '</table>';
print '<div class="center"><input type="submit" class="button" name="sell_btn" value="'.dol_escape_htmltag($langs->trans("ClinicPayCardSell")).'"></div>';
print '</form>';

llxFooter();
$db->close();
