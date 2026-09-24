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

use Luracast\Restler\RestException;

/**
 * \file    htdocs/custom/clinicpay/class/api_clinicpay.class.php
 * \ingroup clinicpay
 * \brief   REST API for charge bills and prepaid cards (spec §3.8).
 *          URL resource is "bills" / "cards"; API class is "Clinicpay"
 *          while the business classes are "Paybill" / "ServiceCard"
 *          (no name clash). The card refund goes through the bill refund
 *          flow (no dedicated endpoint, spec §3.8).
 *
 *          Every endpoint calls the Paybill / ServiceCard classes, so the
 *          same state machine, atomic invoice / consume gates, double-person
 *          refund red line and audit trail as the UI apply. No endpoint
 *          exposes the patient's identity document (only card_no / name
 *          through patient_get_summary, spec §5.6).
 *
 *          Permission matrix (spec §7-F):
 *          - GET  bills, bills/{id}, cards, cards/{id}  -> read
 *          - POST bills                                 -> write
 *          - POST bills/{id}/confirm                    -> pay
 *          - POST bills/{id}/refund                     -> write (draft) / validate (execute)
 *          - POST cards                                 -> write + pay
 *          - POST cards/{id}/consume                    -> consume
 */

dol_include_once('/clinicpay/class/paybill.class.php');
dol_include_once('/clinicpay/class/servicecard.class.php');
dol_include_once('/clinicpay/lib/clinicpay.lib.php');
dol_include_once('/patient/lib/patient.lib.php');

/**
 * API class for ClinicPay module
 *
 * @url     GET /bills
 * @access  protected
 * @class   DolibarrApiAccess {@requires user,external}
 */
class Clinicpay extends DolibarrApi
{
	/**
	 * @var DoliDB $db Database object
	 */
	protected $db;

	/**
	 * Constructor
	 *
	 * @url GET /
	 */
	public function __construct()
	{
		global $db;
		$this->db = $db;
	}

	// ------------------------------------------------------------ bills

	/**
	 * List charge bills.
	 *
	 * @url	GET bills
	 *
	 * @param	string	$q				Ref / card no. / patient name
	 * @param	int		$patient		Patient profile rowid
	 * @param	int		$status			-1 all non-refunded (default), 0 draft, 1 paid, 9 refunded
	 * @param	string	$from			Bill date from (YYYY-MM-DD)
	 * @param	string	$to				Bill date to (YYYY-MM-DD)
	 * @param	int		$limit			Page size (max 100)
	 * @param	int		$page			Page (0-based)
	 * @return	array					Paginated list: total + rows
	 * @throws RestException 403 Not allowed
	 */
	public function indexBills($q = '', $patient = 0, $status = -1, $from = '', $to = '', $limit = 25, $page = 0)
	{
		if (!DolibarrApiAccess::$user->hasRight('clinicpay', 'read')) {
			throw new RestException(403);
		}
		$limit = max(1, min(100, (int) $limit));
		$page = max(0, (int) $page);
		$filters = array(
			'q' => (string) $q,
			'fk_patient' => (int) $patient,
			'status' => (int) $status,
			'from' => $from !== '' ? $this->dateToTs($from, false) : 0,
			'to' => $to !== '' ? $this->dateToTs($to, true) : 0,
		);
		$dao = new Paybill($this->db);
		$result = $dao->search($filters, $limit, $limit * $page);
		if ($result === null) {
			throw new RestException(500, 'Search failed: '.$dao->error);
		}
		$rows = array();
		foreach ($result['rows'] as $r) {
			$rows[] = $this->billListRow($r);
		}
		return array('total' => (int) $result['total'], 'rows' => $rows);
	}

	/**
	 * Get one charge bill with its lines (audited as CLINICPAY_READ).
	 *
	 * @url	GET bills/{id}
	 *
	 * @param	int		$id		Bill rowid
	 * @return	array
	 * @throws RestException 403 Not allowed
	 * @throws RestException 404 Not found
	 */
	public function getBill($id)
	{
		if (!DolibarrApiAccess::$user->hasRight('clinicpay', 'read')) {
			throw new RestException(403);
		}
		$b = $this->loadBill($id);
		patient_audit($this->db, $b->fk_patient, 'CLINICPAY_READ', DolibarrApiAccess::$user, array('ref' => $b->ref, 'bill' => $b->id, 'via' => 'api'));
		return $this->billFields($b);
	}

	/**
	 * Create a draft charge bill.
	 *
	 * Body: { "fk_patient": 3, "note": "...", "lines": [
	 *   { "fk_product": 4, "qty": 2 },                          // product line (price snapshot)
	 *   { "label": "诊金", "price_unit": 30, "vat_rate": 0 }    // custom line
	 * ] }
	 *
	 * @url	POST bills
	 *
	 * @param	array	$request_data	Body
	 * @return	array
	 * @throws RestException 403 Not allowed
	 * @throws RestException 400 Bad parameters / create failed
	 * @throws RestException 409 Validation refused (no lines, patient missing)
	 */
	public function postBill($request_data = null)
	{
		if (!DolibarrApiAccess::$user->hasRight('clinicpay', 'write')) {
			throw new RestException(403);
		}
		$data = is_array($request_data) ? $request_data : array();
		$dao = new Paybill($this->db);
		$result = $dao->create(DolibarrApiAccess::$user, $data);
		if ($result > 0) {
			$dao->fetch($dao->id);
			return $this->billFields($dao);
		}
		if ($result === -2) {
			throw new RestException(409, 'Refused: '.$dao->error);
		}
		throw new RestException(400, 'Create failed: '.$dao->error);
	}

	/**
	 * Confirm the charge: native invoice + payment in one transaction
	 * (pay permission). Idempotent: an already-paid bill returns its
	 * current state without a second invoice. Invoice failure -> 409.
	 *
	 * Body: { "channel": "CASH"|"SCAN", "channel_ref": "..." (SCAN: required) }
	 *
	 * @url	POST bills/{id}/confirm
	 *
	 * @param	int		$id				Bill rowid
	 * @param	array	$request_data	Body
	 * @return	array
	 * @throws RestException 403 Not allowed
	 * @throws RestException 404 Not found
	 * @throws RestException 409 Not a draft / channel invalid / state refused
	 * @throws RestException 400 Confirm failure (invoice/payment error)
	 */
	public function confirmBill($id, $request_data = null)
	{
		if (!DolibarrApiAccess::$user->hasRight('clinicpay', 'pay')) {
			throw new RestException(403);
		}
		$b = $this->loadBill($id);
		if ((int) $b->status === CLINICPAY_BILL_PAID) {
			return $this->billFields($b); // idempotent
		}
		$data = is_array($request_data) ? $request_data : array();
		$channel = isset($data['channel']) ? (string) $data['channel'] : '';
		$channelRef = isset($data['channel_ref']) ? (string) $data['channel_ref'] : '';
		$result = $b->confirm(DolibarrApiAccess::$user, $channel, $channelRef);
		if ($result > 0) {
			$b->fetch($b->id);
			return $this->billFields($b);
		}
		if ($result === -2) {
			throw new RestException(409, 'Refused: '.$b->error);
		}
		throw new RestException(400, 'Confirm failed: '.$b->error);
	}

	/**
	 * Refund a paid bill. Two steps preserved (spec §3.3-3 / §5.4):
	 * default body { "reason": "..." } creates the refund draft (write);
	 * body { "reason": "...", "execute": true } also executes it (validate).
	 * The executor must differ from the draft creator unless admin
	 * (double-person red line -> 409 otherwise).
	 *
	 * @url	POST bills/{id}/refund
	 *
	 * @param	int		$id				Bill rowid
	 * @param	array	$request_data	Body { reason, execute? }
	 * @return	array
	 * @throws RestException 403 Not allowed
	 * @throws RestException 404 Not found
	 * @throws RestException 400 Reason missing
	 * @throws RestException 409 Not paid / draft exists / same person / already refunded
	 */
	public function refundBill($id, $request_data = null)
	{
		$data = is_array($request_data) ? $request_data : array();
		$execute = !empty($data['execute']);
		if ($execute) {
			// The draft creation (write) is included in the validated flow;
			// executing strictly needs validate.
			if (!DolibarrApiAccess::$user->hasRight('clinicpay', 'validate')) {
				throw new RestException(403);
			}
		} else {
			if (!DolibarrApiAccess::$user->hasRight('clinicpay', 'write')) {
				throw new RestException(403);
			}
		}
		$b = $this->loadBill($id);
		$reason = isset($data['reason']) ? trim((string) $data['reason']) : '';
		if ($reason === '') {
			throw new RestException(400, 'Refund reason required');
		}
		$result = $b->createRefundDraft(DolibarrApiAccess::$user, $reason);
		if ($result === -2 && $execute && $b->error === 'ClinicPayErrRefundDraftExists') {
			// A pending draft already exists (e.g. created in a previous
			// call): proceed straight to the execution step.
			$result = 1;
		}
		if ($result === -2) {
			throw new RestException(409, 'Refused: '.$b->error);
		}
		if ($result < 0) {
			throw new RestException(400, 'Refund draft failed: '.$b->error);
		}
		if (!$execute) {
			$b->fetch($b->id);
			return $this->billFields($b);
		}
		$result = $b->executeRefund(DolibarrApiAccess::$user);
		if ($result === -2) {
			throw new RestException(409, 'Refused: '.$b->error);
		}
		if ($result < 0) {
			throw new RestException(400, 'Refund failed: '.$b->error);
		}
		$b->fetch($b->id);
		return $this->billFields($b);
	}

	// ------------------------------------------------------------ cards

	/**
	 * List prepaid cards.
	 *
	 * @url	GET cards
	 *
	 * @param	string	$q			Card ref / patient card no. / patient name
	 * @param	int		$patient	Patient profile rowid
	 * @param	int		$status		-1 all (default), 0 valid, 1 used, 2 expired, 3 refunded
	 * @param	string	$card_type	COUNT / VALUE
	 * @param	int		$limit		Page size (max 100)
	 * @param	int		$page		Page (0-based)
	 * @return	array				Paginated list: total + rows
	 * @throws RestException 403 Not allowed
	 */
	public function indexCards($q = '', $patient = 0, $status = -1, $card_type = '', $limit = 25, $page = 0)
	{
		if (!DolibarrApiAccess::$user->hasRight('clinicpay', 'read')) {
			throw new RestException(403);
		}
		$limit = max(1, min(100, (int) $limit));
		$page = max(0, (int) $page);
		$filters = array(
			'q' => (string) $q,
			'fk_patient' => (int) $patient,
			'status' => (int) $status,
			'card_type' => (string) $card_type,
		);
		$dao = new ServiceCard($this->db);
		$result = $dao->search($filters, $limit, $limit * $page);
		if ($result === null) {
			throw new RestException(500, 'Search failed: '.$dao->error);
		}
		$rows = array();
		foreach ($result['rows'] as $r) {
			$rows[] = $this->cardListRow($r);
		}
		return array('total' => (int) $result['total'], 'rows' => $rows);
	}

	/**
	 * Get one prepaid card with its movement log.
	 *
	 * @url	GET cards/{id}
	 *
	 * @param	int		$id		Card rowid
	 * @return	array
	 * @throws RestException 403 Not allowed
	 * @throws RestException 404 Not found
	 */
	public function getCard($id)
	{
		if (!DolibarrApiAccess::$user->hasRight('clinicpay', 'read')) {
			throw new RestException(403);
		}
		$c = $this->loadCard($id);
		return $this->cardFields($c);
	}

	/**
	 * Sell a prepaid card: one charge bill + native invoice + payment +
	 * the card row + CREATE log, one transaction (spec §3.4). Needs write
	 * + pay permissions.
	 *
	 * Body: { "fk_patient": 3, "card_type": "COUNT"|"VALUE",
	 *         "count": 10 | "value": 500.00, "fk_product"?: 4,
	 *         "date_end"?: "2027-12-31", "channel": "CASH"|"SCAN",
	 *         "channel_ref"?: "...", "note"?: "..." }
	 *
	 * @url	POST cards
	 *
	 * @param	array	$request_data	Body
	 * @return	array
	 * @throws RestException 403 Not allowed
	 * @throws RestException 400 Bad parameters / sell failed
	 * @throws RestException 409 Validation refused (card type / channel / patient)
	 */
	public function postCard($request_data = null)
	{
		$user = DolibarrApiAccess::$user;
		if (!$user->hasRight('clinicpay', 'write') || !$user->hasRight('clinicpay', 'pay')) {
			throw new RestException(403);
		}
		$data = is_array($request_data) ? $request_data : array();
		$dao = new ServiceCard($this->db);
		$result = $dao->sell($user, $data);
		if ($result > 0) {
			$dao->fetch($dao->id);
			return $this->cardFields($dao);
		}
		if ($result === -2) {
			throw new RestException(409, 'Refused: '.$dao->error);
		}
		throw new RestException(400, 'Sell failed: '.$dao->error);
	}

	/**
	 * Consume a prepaid card (no invoice: the charge was paid when the
	 * card was sold). COUNT cards consume 1 per call; VALUE cards consume
	 * the "value" amount. Overdraw / used up / expired -> 409.
	 *
	 * Body: { "value": 25.5, "note": "..." }   (VALUE cards)
	 * Body: { "note": "..." }                  (COUNT cards)
	 *
	 * @url	POST cards/{id}/consume
	 *
	 * @param	int		$id				Card rowid
	 * @param	array	$request_data	Body
	 * @return	array
	 * @throws RestException 403 Not allowed
	 * @throws RestException 404 Not found
	 * @throws RestException 409 Overdraw / used up / expired / invalid state
	 * @throws RestException 400 Consume failure
	 */
	public function consumeCard($id, $request_data = null)
	{
		if (!DolibarrApiAccess::$user->hasRight('clinicpay', 'consume')) {
			throw new RestException(403);
		}
		$c = $this->loadCard($id);
		$data = is_array($request_data) ? $request_data : array();
		$amount = isset($data['value']) ? (float) $data['value'] : 0.0;
		$note = isset($data['note']) ? (string) $data['note'] : '';
		$result = $c->consume(DolibarrApiAccess::$user, $amount, $note);
		if ($result > 0) {
			$c->fetch($c->id);
			return $this->cardFields($c);
		}
		if ($result === -2) {
			throw new RestException(409, 'Refused: '.$c->error);
		}
		throw new RestException(400, 'Consume failed: '.$c->error);
	}

	// ------------------------------------------------------------ helpers

	/**
	 * @param	int		$id		Bill rowid
	 * @return	Paybill
	 * @throws	RestException 404
	 */
	private function loadBill($id)
	{
		$b = new Paybill($this->db);
		if ((int) $id <= 0 || $b->fetch((int) $id) <= 0) {
			throw new RestException(404, 'Bill not found');
		}
		return $b;
	}

	/**
	 * @param	int		$id		Card rowid
	 * @return	ServiceCard
	 * @throws	RestException 404
	 */
	private function loadCard($id)
	{
		$c = new ServiceCard($this->db);
		if ((int) $id <= 0 || $c->fetch((int) $id) <= 0) {
			throw new RestException(404, 'Card not found');
		}
		return $c;
	}

	/**
	 * One bill list row. No patient identity document.
	 *
	 * @param	object	$r	Row from Paybill::search
	 * @return	array
	 */
	private function billListRow($r)
	{
		return array(
			'id' => (int) $r->rowid,
			'ref' => $r->ref,
			'fk_patient' => (int) $r->fk_patient,
			'card_no' => $r->card_no ?: null,
			'patient_name' => $r->patient_name ?: null,
			'status' => (int) $r->status,
			'amount_total' => (float) $r->amount_total,
			'channel' => (string) $r->channel,
			'date_pay' => $r->date_pay ? dol_print_date($this->db->jdate($r->date_pay), 'dayhourrfc') : null,
			'date_creation' => dol_print_date($this->db->jdate($r->date_creation), 'dayhourrfc'),
		);
	}

	/**
	 * Full bill fields (with lines). No patient identity document.
	 *
	 * @param	Paybill	$b	Loaded object
	 * @return	array
	 */
	private function billFields(Paybill $b)
	{
		$summary = function_exists('patient_get_summary') ? patient_get_summary($this->db, $b->fk_patient) : null;
		$lines = array();
		foreach ((array) $b->lines as $l) {
			$lines[] = array(
				'id' => isset($l['id']) ? (int) $l['id'] : null,
				'fk_product' => $l['fk_product'],
				'product_ref' => $l['product_ref'] ?: null,
				'label' => $l['label'],
				'qty' => (float) $l['qty'],
				'price_unit' => (float) $l['price_unit'],
				'vat_rate' => (float) $l['vat_rate'],
				'subprice_total' => (float) $l['subprice_total'],
			);
		}
		return array(
			'id' => (int) $b->id,
			'ref' => $b->ref,
			'fk_patient' => (int) $b->fk_patient,
			'card_no' => $summary ? $summary['card_no'] : null,
			'patient_name' => $summary ? $summary['name'] : null,
			'fk_invoice' => $b->fk_invoice !== null ? (int) $b->fk_invoice : null,
			'status' => (int) $b->status,
			'amount_total' => (float) $b->amount_total,
			'channel' => (string) $b->channel,
			'channel_ref' => (string) $b->channel_ref ?: null,
			'date_pay' => $b->date_pay ? dol_print_date($b->date_pay, 'dayhourrfc') : null,
			'note' => $b->note ?: null,
			'date_creation' => $b->date_creation ? dol_print_date($b->date_creation, 'dayhourrfc') : null,
			'lines' => $lines,
		);
	}

	/**
	 * One card list row. No patient identity document.
	 *
	 * @param	object	$r	Row from ServiceCard::search
	 * @return	array
	 */
	private function cardListRow($r)
	{
		return array(
			'id' => (int) $r->rowid,
			'ref' => $r->ref,
			'fk_patient' => (int) $r->fk_patient,
			'card_no' => $r->card_no ?: null,
			'patient_name' => $r->patient_name ?: null,
			'card_type' => (string) $r->card_type,
			'total_count' => (int) $r->total_count,
			'used_count' => (int) $r->used_count,
			'total_value' => (float) $r->total_value,
			'used_value' => (float) $r->used_value,
			'date_end' => $r->date_end ?: null,
			'status' => (int) $r->status,
			'date_creation' => dol_print_date($this->db->jdate($r->date_creation), 'dayhourrfc'),
		);
	}

	/**
	 * Full card fields (with movement log). No patient identity document.
	 *
	 * @param	ServiceCard	$c	Loaded object
	 * @return	array
	 */
	private function cardFields(ServiceCard $c)
	{
		$summary = function_exists('patient_get_summary') ? patient_get_summary($this->db, $c->fk_patient) : null;
		$c->fetchLogs();
		$logs = array();
		foreach ((array) $c->logs as $l) {
			$logs[] = array(
				'id' => (int) $l['id'],
				'op' => (string) $l['op'],
				'count_delta' => (int) $l['count_delta'],
				'value_delta' => (float) $l['value_delta'],
				'fk_bill' => $l['fk_bill'],
				'note' => $l['note'] ?: null,
				'date' => $l['date'] ? dol_print_date($l['date'], 'dayhourrfc') : null,
			);
		}
		return array(
			'id' => (int) $c->id,
			'ref' => $c->ref,
			'fk_patient' => (int) $c->fk_patient,
			'card_no' => $summary ? $summary['card_no'] : null,
			'patient_name' => $summary ? $summary['name'] : null,
			'fk_product' => $c->fk_product,
			'card_type' => (string) $c->card_type,
			'total_count' => (int) $c->total_count,
			'used_count' => (int) $c->used_count,
			'total_value' => (float) $c->total_value,
			'used_value' => (float) $c->used_value,
			'date_start' => $c->date_start ?: null,
			'date_end' => $c->date_end ?: null,
			'status' => (int) $c->status,
			'note' => $c->note ?: null,
			'date_creation' => $c->date_creation ? dol_print_date($c->date_creation, 'dayhourrfc') : null,
			'logs' => $logs,
		);
	}

	/**
	 * @param	string	$s		YYYY-MM-DD[ HH:MM[:SS]]
	 * @param	bool	$endOfDay	Use 23:59:59 when no time given
	 * @return	int				Timestamp or 0
	 */
	private function dateToTs($s, $endOfDay)
	{
		if (!preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})(?:[ T]([0-9]{2}):([0-9]{2})(?::([0-9]{2}))?)?$/', trim($s), $m)) {
			return 0;
		}
		$h = isset($m[4]) ? (int) $m[4] : ($endOfDay ? 23 : 0);
		$i = isset($m[5]) ? (int) $m[5] : ($endOfDay ? 59 : 0);
		$sec = isset($m[6]) ? (int) $m[6] : ($endOfDay ? 59 : 0);
		return (int) dol_mktime($h, $i, $sec, (int) $m[2], (int) $m[3], (int) $m[1]);
	}
}
