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
 * \file    htdocs/custom/clinicpay/lib/clinicpay.lib.php
 * \ingroup clinicpay
 * \brief   Shared helpers: bill / card status labels, channel labels,
 *          lists by patient. Audit goes through modPatient's
 *          patient_audit() with CLINICPAY_* actions (spec §5.5).
 */

dol_include_once('/patient/lib/patient.lib.php');

// ---------------------------------------------------------------- Bill status
define('CLINICPAY_BILL_DRAFT', 0);
define('CLINICPAY_BILL_PAID', 1);
define('CLINICPAY_BILL_REFUNDED', 9);

// ---------------------------------------------------------------- Card status
define('CLINICPAY_CARD_VALID', 0);
define('CLINICPAY_CARD_USED', 1);
define('CLINICPAY_CARD_EXPIRED', 2);
define('CLINICPAY_CARD_REFUNDED', 3);

// ---------------------------------------------------------------- Card type
define('CLINICPAY_CARD_COUNT', 'COUNT');
define('CLINICPAY_CARD_VALUE', 'VALUE');

// ---------------------------------------------------------------- Pay channels
define('CLINICPAY_CHANNEL_CASH', 'CASH');
define('CLINICPAY_CHANNEL_SCAN', 'SCAN');

// ---------------------------------------------------------------- Card log ops
define('CLINICPAY_LOG_CREATE', 'CREATE');
define('CLINICPAY_LOG_CHARGE', 'CHARGE');
define('CLINICPAY_LOG_CONSUME', 'CONSUME');
define('CLINICPAY_LOG_REFUND', 'REFUND');
define('CLINICPAY_LOG_EXPIRE', 'EXPIRE');

/**
 * @param	int		$status		Bill status value
 * @return	string				Translated label
 */
function clinicpay_bill_status_label($status)
{
	global $langs;
	$langs->load('clinicpay@clinicpay');
	switch ((int) $status) {
		case CLINICPAY_BILL_PAID:
			return $langs->trans('ClinicPayBillPaid');
		case CLINICPAY_BILL_REFUNDED:
			return $langs->trans('ClinicPayBillRefunded');
		default:
			return $langs->trans('ClinicPayBillDraft');
	}
}

/**
 * @param	int		$status		Bill status value
 * @return	string				Badge HTML
 */
function clinicpay_bill_status_badge($status)
{
	$cls = array(CLINICPAY_BILL_DRAFT => 'badge-status0', CLINICPAY_BILL_PAID => 'badge-status4',
		CLINICPAY_BILL_REFUNDED => 'badge-status9');
	$status = (int) $status;
	return '<span class="badge '.(isset($cls[$status]) ? $cls[$status] : 'badge-status0').'">'.clinicpay_bill_status_label($status).'</span>';
}

/**
 * @param	int		$status		Card status value
 * @return	string				Translated label
 */
function clinicpay_card_status_label($status)
{
	global $langs;
	$langs->load('clinicpay@clinicpay');
	switch ((int) $status) {
		case CLINICPAY_CARD_USED:
			return $langs->trans('ClinicPayCardUsed');
		case CLINICPAY_CARD_EXPIRED:
			return $langs->trans('ClinicPayCardExpired');
		case CLINICPAY_CARD_REFUNDED:
			return $langs->trans('ClinicPayCardRefunded');
		default:
			return $langs->trans('ClinicPayCardValid');
	}
}

/**
 * @param	int		$status		Card status value
 * @return	string				Badge HTML
 */
function clinicpay_card_status_badge($status)
{
	$cls = array(CLINICPAY_CARD_VALID => 'badge-status4', CLINICPAY_CARD_USED => 'badge-status8',
		CLINICPAY_CARD_EXPIRED => 'badge-status9', CLINICPAY_CARD_REFUNDED => 'badge-status5');
	$status = (int) $status;
	return '<span class="badge '.(isset($cls[$status]) ? $cls[$status] : 'badge-status4').'">'.clinicpay_card_status_label($status).'</span>';
}

/**
 * @param	string	$type		Card type (COUNT / VALUE)
 * @return	string				Translated label
 */
function clinicpay_card_type_label($type)
{
	global $langs;
	$langs->load('clinicpay@clinicpay');
	return $type === CLINICPAY_CARD_COUNT ? $langs->trans('ClinicPayCardTypeCount') : $langs->trans('ClinicPayCardTypeValue');
}

/**
 * @param	string	$channel	Channel code (CASH / SCAN)
 * @return	string				Translated label
 */
function clinicpay_channel_label($channel)
{
	global $langs;
	$langs->load('clinicpay@clinicpay');
	switch ((string) $channel) {
		case CLINICPAY_CHANNEL_CASH:
			return $langs->trans('ClinicPayChannelCash');
		case CLINICPAY_CHANNEL_SCAN:
			return $langs->trans('ClinicPayChannelScan');
		default:
			return '';
	}
}

/**
 * Charge bills of one patient (patient card tab / filter), newest first.
 *
 * @param	DoliDB	$db			Database handler
 * @param	int		$fkPatient	Patient profile rowid
 * @param	int		$limit		Max rows
 * @return	array<int,object>	Rows
 */
function clinicpay_bill_list_by_patient($db, $fkPatient, $limit = 50)
{
	global $conf;

	$out = array();
	$sql = "SELECT b.rowid, b.ref, b.status, b.amount_total, b.channel, b.date_pay, b.date_creation, b.fk_medrecord";
	if (isModEnabled('medrecord')) {
		$sql .= ", m.ref as medrecord_ref";
	}
	if (isModEnabled('prescription')) {
		// Linked prescriptions for the patient tab column (bill_line rows may
		// carry fk_prescription from the dispense/prescription charge flows).
		// "id:ref" pairs, exploded client side.
		$sql .= ", (SELECT GROUP_CONCAT(DISTINCT CONCAT(bl.fk_prescription, ':', p.ref)) FROM ".$db->prefix()."clinicpay_bill_line as bl";
		$sql .= " JOIN ".$db->prefix()."prescription as p ON p.rowid = bl.fk_prescription";
		$sql .= " WHERE bl.fk_bill = b.rowid) as presc_pairs";
	}
	$sql .= " FROM ".$db->prefix()."clinicpay_bill as b";
	if (isModEnabled('medrecord')) {
		$sql .= " LEFT JOIN ".$db->prefix()."medrecord as m ON m.rowid = b.fk_medrecord";
	}
	$sql .= " WHERE b.fk_patient = ".((int) $fkPatient);
	$sql .= $db->order('b.rowid', 'DESC');
	$sql .= $db->plimit((int) $limit);
	$resql = $db->query($sql);
	if ($resql) {
		while ($o = $db->fetch_object($resql)) {
			$out[] = $o;
		}
		$db->free($resql);
	}
	return $out;
}

/**
 * Sum of charge bills tied to one visit (medrecord). Drives the
 * "本次合计" column on the patient visit list (design §5.2).
 *
 * @param	DoliDB	$db			Database handler
 * @param	int		$fkMedrecord	Visit rowid
 * @return	float				Sum of amount_total (0 if none)
 */
function clinicpay_bill_total_by_medrecord($db, $fkMedrecord)
{
	$sql = "SELECT COALESCE(SUM(amount_total), 0) AS n FROM ".$db->prefix()."clinicpay_bill";
	$sql .= " WHERE fk_medrecord = ".((int) $fkMedrecord);
	$resql = $db->query($sql);
	if ($resql) {
		$o = $db->fetch_object($resql);
		$db->free($resql);
		return $o ? (float) $o->n : 0.0;
	}
	return 0.0;
}

/**
 * Charge bills tied to one visit (medrecord), newest first.
 *
 * @param	DoliDB	$db			Database handler
 * @param	int		$fkMedrecord	Visit rowid
 * @param	int		$limit		Max rows
 * @return	array<int,object>	Rows
 */
function clinicpay_bill_list_by_medrecord($db, $fkMedrecord, $limit = 50)
{
	$out = array();
	$sql = "SELECT b.rowid, b.ref, b.status, b.amount_total, b.channel, b.date_pay, b.date_creation";
	$sql .= " FROM ".$db->prefix()."clinicpay_bill as b";
	$sql .= " WHERE b.fk_medrecord = ".((int) $fkMedrecord);
	$sql .= $db->order('b.rowid', 'DESC');
	$sql .= $db->plimit((int) $limit);
	$resql = $db->query($sql);
	if ($resql) {
		while ($o = $db->fetch_object($resql)) {
			$out[] = $o;
		}
		$db->free($resql);
	}
	return $out;
}

/**
 * Product / service options for the bill line editor (ref - label).
 *
 * @param	DoliDB	$db			Database handler
 * @param	int		$limit		Max rows
 * @return	array<int,string>	rowid => display label
 */
function clinicpay_product_options($db, $limit = 500)
{
	$out = array();
	$sql = "SELECT rowid, ref, label, price FROM ".$db->prefix()."product";
	$sql .= " ORDER BY ref";
	$sql .= $db->plimit((int) $limit);
	$resql = $db->query($sql);
	if ($resql) {
		while ($o = $db->fetch_object($resql)) {
			$out[(int) $o->rowid] = $o->ref.' - '.$o->label.' ('.price($o->price).')';
		}
		$db->free($resql);
	}
	return $out;
}

/**
 * Native payment mode code (llx_c_paiement) of a clinic channel.
 *
 * @param	string	$channel	CASH / SCAN
 * @return	string				Native code, '' if unknown
 */
function clinicpay_paiement_code($channel)
{
	switch (strtoupper((string) $channel)) {
		case CLINICPAY_CHANNEL_CASH:
			return 'CASH';
		case CLINICPAY_CHANNEL_SCAN:
			return 'CB';
		default:
			return '';
	}
}

/**
 * Convert a numeric amount to Chinese capital form (人民币大写, GB/T 15835).
 * Reuses the chinadoc implementation (identical algorithm, independent
 * copy so clinicpay does not depend on modChinaDoc).
 * Examples: 1002.30 -> 壹仟零贰元叁角整, 0.5 -> 伍角整
 *
 * @param	float|string	$amount		Numeric amount (>= 0)
 * @return	string					Chinese capital amount, '' on invalid input
 */
function clinicpay_amount_to_chinese($amount)
{
	$amount = (float) $amount;
	if ($amount < 0 || $amount >= 1000000000000) {
		return '';
	}

	$digits = array('零', '壹', '贰', '叁', '肆', '伍', '陆', '柒', '捌', '玖');
	$sections = array('', '万', '亿', '万亿');
	$positions = array('', '拾', '佰', '仟');

	// Work on cents to avoid float drift
	$cents = (int) round($amount * 100);
	$yuan = (int) ($cents / 100);
	$jiao = (int) (($cents % 100) / 10);
	$fen = $cents % 10;

	if ($yuan === 0 && $jiao === 0 && $fen === 0) {
		return '零元整';
	}

	// Integer part per 4-digit section
	$parts = array();
	$rest = $yuan;
	$sectionIndex = 0;
	while ($rest > 0) {
		$parts[] = $rest % 10000;
		$rest = (int) ($rest / 10000);
		$sectionIndex++;
	}

	$intText = '';
	$needZero = false; // whether a zero link is needed before the next non-zero section
	$highest = true;   // the first (highest) non-empty section must not start with a link zero
	for ($i = count($parts) - 1; $i >= 0; $i--) {
		$section = $parts[$i];
		$sectionText = '';
		$zeroInMiddle = false;

		for ($p = 3; $p >= 0; $p--) {
			$d = (int) ($section / pow(10, $p)) % 10;
			if ($d > 0) {
				if (($zeroInMiddle || $needZero) && !$highest) {
					$sectionText .= '零';
				}
				$sectionText .= $digits[$d].$positions[$p];
				$zeroInMiddle = false;
				$needZero = false;
				$highest = false;
			} else {
				$zeroInMiddle = true;
			}
		}

		if ($section > 0) {
			$intText .= $sectionText.$sections[$i];
			$highest = false;
			// a non-zero section followed by a lower section starting with 0 needs a link zero
			if ($section % 10 === 0) {
				$needZero = true;
			}
		} elseif ($intText !== '') {
			$needZero = true;
		}
	}
	if ($yuan > 0) {
		$intText .= '元';
	}

	// Decimal part
	$decText = '';
	if ($jiao > 0) {
		$decText .= $digits[$jiao].'角';
	}
	if ($fen > 0) {
		if ($jiao === 0 && $yuan > 0) {
			$decText .= '零';
		}
		$decText .= $digits[$fen].'分';
	}

	$result = $intText.$decText;
	if ($fen === 0) {
		// x角整 / 元整
		$result .= '整';
	}
	return $result;
}

/**
 * Prepaid cards of one patient (patient card tab), newest first.
 *
 * @param	DoliDB	$db			Database handler
 * @param	int		$fkPatient	Patient profile rowid
 * @param	int		$limit		Max rows
 * @return	array<int,object>	Rows
 */
function clinicpay_card_list_by_patient($db, $fkPatient, $limit = 50)
{
	$out = array();
	$sql = "SELECT c.rowid, c.ref, c.card_type, c.status, c.total_count, c.used_count,";
	$sql .= " c.total_value, c.used_value, c.date_end, c.date_creation";
	$sql .= " FROM ".$db->prefix()."clinicpay_card as c";
	$sql .= " WHERE c.fk_patient = ".((int) $fkPatient);
	$sql .= $db->order('c.rowid', 'DESC');
	$sql .= $db->plimit((int) $limit);
	$resql = $db->query($sql);
	if ($resql) {
		while ($o = $db->fetch_object($resql)) {
			$out[] = $o;
		}
		$db->free($resql);
	}
	return $out;
}
