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
 * \file    htdocs/custom/clinicpay/class/servicecard.class.php
 * \ingroup clinicpay
 * \brief   A prepaid count/value card (spec §3.4). Never deleted; the log
 *          table is append-only. Phase 1 skeleton: fetch / search. The atomic
 *          sell/charge, consume and refund transactions land in phase 3.
 */

dol_include_once('/clinicpay/lib/clinicpay.lib.php');
dol_include_once('/clinicpay/class/cardnumbering.class.php');
dol_include_once('/clinicpay/class/paybill.class.php');
dol_include_once('/patient/lib/patient.lib.php');

/**
 * Class ServiceCard
 */
class ServiceCard extends CommonObject
{
	/** @var string Element type (for hooks / REST) */
	public $element = 'clinicpay_card';

	/** @var string */
	public $table_element = 'clinicpay_card';

	/** @var int */
	public $id;

	public $entity;
	public $ref;
	/** @var int Patient profile rowid */
	public $fk_patient;
	/** @var int|null Product/service rowid the card maps to */
	public $fk_product;
	/** @var string COUNT / VALUE */
	public $card_type;
	public $total_count;
	public $used_count;
	public $total_value;
	public $used_value;
	/** @var string|null YYYY-MM-DD */
	public $date_start;
	/** @var string|null YYYY-MM-DD */
	public $date_end;
	/** @var int 0 valid, 1 used, 2 expired, 3 refunded */
	public $status = CLINICPAY_CARD_VALID;
	public $note;
	/** @var int */
	public $fk_user_creat;
	/** @var int Unix timestamp */
	public $date_creation;

	/** @var string Patient name filled on fetch */
	public $patient_name;
	/** @var string Patient card no filled on fetch */
	public $card_no;

	/** @var string Last error */
	public $error = '';

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * @param	int	$id	Rowid
	 * @return	int	1 ok, 0 not found, -1 error
	 */
	public function fetch($id)
	{
		$sql = "SELECT c.rowid, c.entity, c.ref, c.fk_patient, c.fk_product, c.card_type,";
		$sql .= " c.total_count, c.used_count, c.total_value, c.used_value, c.date_start, c.date_end,";
		$sql .= " c.status, c.note, c.fk_user_creat, c.date_creation";
		$sql .= " FROM ".$this->db->prefix()."clinicpay_card as c";
		$sql .= " WHERE c.rowid = ".((int) $id);
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$obj = $this->db->fetch_object($resql);
		$this->db->free($resql);
		if (!$obj) {
			return 0;
		}
		$this->id = (int) $obj->rowid;
		$this->entity = (int) $obj->entity;
		$this->ref = $obj->ref;
		$this->fk_patient = (int) $obj->fk_patient;
		$this->fk_product = $obj->fk_product !== null ? (int) $obj->fk_product : null;
		$this->card_type = $obj->card_type;
		$this->total_count = (int) $obj->total_count;
		$this->used_count = (int) $obj->used_count;
		$this->total_value = (float) $obj->total_value;
		$this->used_value = (float) $obj->used_value;
		$this->date_start = $obj->date_start;
		$this->date_end = $obj->date_end;
		$this->status = (int) $obj->status;
		$this->note = (string) $obj->note;
		$this->fk_user_creat = (int) $obj->fk_user_creat;
		$this->date_creation = $this->db->jdate($obj->date_creation);
		return 1;
	}

	/**
	 * Paged list of cards.
	 *
	 * @param	array	$f		Filters: q, status (-1 = all), card_type, fk_patient
	 * @param	int		$limit	Page size
	 * @param	int		$offset	Offset
	 * @return	array{total:int,rows:array<int,object>}|null
	 */
	public function search(array $f, $limit = 25, $offset = 0)
	{
		global $conf;

		$from = " FROM ".$this->db->prefix()."clinicpay_card as c";
		$from .= " INNER JOIN ".$this->db->prefix()."patient_profile as pp ON pp.rowid = c.fk_patient";
		$from .= " INNER JOIN ".$this->db->prefix()."societe as s ON s.rowid = pp.fk_soc";
		$where = " WHERE c.entity = ".((int) $conf->entity);
		if (!empty($f['q'])) {
			$like = "'%".$this->db->escape(trim($f['q']))."%'";
			$where .= " AND (c.ref LIKE ".$like." OR pp.card_no LIKE ".$like." OR s.nom LIKE ".$like.")";
		}
		if (isset($f['status']) && (int) $f['status'] >= 0) {
			$where .= " AND c.status = ".((int) $f['status']);
		}
		if (!empty($f['card_type'])) {
			$where .= " AND c.card_type = '".$this->db->escape($f['card_type'])."'";
		}
		if (!empty($f['fk_patient'])) {
			$where .= " AND c.fk_patient = ".((int) $f['fk_patient']);
		}

		$sql = "SELECT COUNT(*) as n".$from.$where;
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return null;
		}
		$total = (int) $this->db->fetch_object($resql)->n;

		$select = "SELECT c.rowid, c.ref, c.fk_patient, c.card_type, c.total_count, c.used_count,";
		$select .= " c.total_value, c.used_value, c.date_end, c.status, c.date_creation, pp.card_no, s.nom as patient_name";
		$sql = $select.$from.$where;
		$sql .= $this->db->order('c.rowid', 'DESC');
		$sql .= $this->db->plimit((int) $limit, (int) $offset);
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return null;
		}
		$rows = array();
		while ($o = $this->db->fetch_object($resql)) {
			$rows[] = $o;
		}
		$this->db->free($resql);
		return array('total' => $total, 'rows' => $rows);
	}

	/**
	 * Prepaid cards of one patient, newest first.
	 *
	 * @param	DoliDB	$db			Database handler
	 * @param	int		$fkPatient	Patient profile rowid
	 * @param	int		$limit		Max rows
	 * @return	array<int,object>	Rows
	 */
	public static function fetchAllByPatient($db, $fkPatient, $limit = 50)
	{
		return clinicpay_card_list_by_patient($db, $fkPatient, $limit);
	}

	// ================================================================= //
	// Phase 3: sell / charge / consume / refund (spec §3.4)               //
	// ================================================================= //

	/**
	 * Sell a new card: one charge bill (its line is the card product) +
	 * native invoice + payment + the card row + CREATE log, all inside one
	 * transaction (spec: "售卖走收费单，确认后建卡，同一事务"). Requires
	 * write + pay permissions, checked by the caller page.
	 *
	 * @param	User	$user	Acting user (write + pay)
	 * @param	array	$data	{fk_patient:int, card_type:COUNT|VALUE, fk_product?:int,
	 *                        	 count?:int, value?:float, date_end?:'YYYY-MM-DD',
	 *                        	 channel:CASH|SCAN, channel_ref?:string, note?:string}
	 * @return	int				1 ok (sets ->id, ->ref, ->bill_id), -2 refused, -1 error
	 */
	public function sell(User $user, array $data)
	{
		return $this->sellOrCharge($user, $data, true);
	}

	/**
	 * Top up an existing card: same shape as sell() (a charge bill is
	 * created and confirmed), then the card balance is increased and a
	 * CHARGE log appended, all in one transaction.
	 *
	 * @param	User	$user	Acting user (write + pay)
	 * @param	array	$data	{count?:int, value?:float, fk_product?:int,
	 *                        	 channel:CASH|SCAN, channel_ref?:string, note?:string}
	 * @return	int				1 ok (sets ->bill_id), -2 refused, -1 error
	 */
	public function charge(User $user, array $data)
	{
		return $this->sellOrCharge($user, $data, false);
	}

	/**
	 * Shared sell/charge transaction. The inner Paybill::create() and
	 * confirm() only increment/decrement DoliDB's transaction depth; the
	 * real COMMIT happens here at the end, so bill + invoice + payment +
	 * card are atomic.
	 *
	 * @param	User	$user		Acting user
	 * @param	array	$data		See sell()/charge()
	 * @param	bool	$isNew		True = create a card, false = top up $this->id
	 * @return	int					1 ok, -2 refused, -1 error
	 */
	private function sellOrCharge(User $user, array $data, $isNew)
	{
		global $conf;

		$this->error = '';
		// charge(): load the card first, the patient/type come from the row
		if (!$isNew) {
			if ($this->id <= 0 || $this->fetch($this->id) <= 0) {
				$this->error = 'ClinicPayErrCardInvalid';
				return -2;
			}
			if ((int) $this->status === CLINICPAY_CARD_REFUNDED) {
				$this->error = 'ClinicPayErrCardRefundState';
				return -2;
			}
		}
		$fkPatient = $isNew ? (int) (isset($data['fk_patient']) ? $data['fk_patient'] : 0) : (int) $this->fk_patient;
		$cardType = strtoupper((string) (isset($data['card_type']) ? $data['card_type'] : $this->card_type));
		if ($fkPatient <= 0) {
			$this->error = 'ClinicPayErrPatient';
			return -2;
		}
		if (!in_array($cardType, array(CLINICPAY_CARD_COUNT, CLINICPAY_CARD_VALUE), true)) {
			$this->error = 'ClinicPayErrCardType';
			return -2;
		}
		$count = isset($data['count']) ? (int) $data['count'] : 0;
		$value = isset($data['value']) ? price2num((float) $data['value'], 'MT') : 0.0;
		if ($cardType === CLINICPAY_CARD_COUNT && $count <= 0) {
			$this->error = 'ClinicPayErrCardCount';
			return -2;
		}
		if ($cardType === CLINICPAY_CARD_VALUE && $value <= 0) {
			$this->error = 'ClinicPayErrCardValue';
			return -2;
		}
		$channel = strtoupper((string) (isset($data['channel']) ? $data['channel'] : ''));
		if (!in_array($channel, array(CLINICPAY_CHANNEL_CASH, CLINICPAY_CHANNEL_SCAN), true)) {
			$this->error = 'ClinicPayErrChannel';
			return -2;
		}
		$channelRef = trim((string) (isset($data['channel_ref']) ? $data['channel_ref'] : ''));
		if ($channel === CLINICPAY_CHANNEL_SCAN && $channelRef === '') {
			$this->error = 'ClinicPayErrRefRequired';
			return -2;
		}

		$this->db->begin();
		try {
			// 1) the charge bill (draft) with one card line. The invoice
			// amount always equals the card value: COUNT cards sell the card
			// product (qty 1, product price) or a custom line; VALUE cards
			// use a custom line priced at the top-up value.
			$fkProduct = isset($data['fk_product']) && (int) $data['fk_product'] > 0 ? (int) $data['fk_product'] : 0;
			$bill = new Paybill($this->db);
			if ($cardType === CLINICPAY_CARD_COUNT && $fkProduct > 0) {
				$line = array('fk_product' => $fkProduct, 'qty' => 1);
			} else {
				$label = trim((string) (isset($data['label']) ? $data['label'] : ''));
				if ($label === '') {
					$label = 'Prepaid card'; // fallback; the page passes a translated label
				}
				if ($fkProduct > 0) {
					$p = $this->productRef($fkProduct);
					if ($p !== '') {
						$label = $p.' - '.$label;
					}
				}
				$line = array('label' => $label, 'qty' => 1, 'price_unit' => $value, 'vat_rate' => (float) getDolGlobalString('CLINICPAY_DEFAULT_VAT', '0'));
			}
			$rc = $bill->create($user, array(
				'fk_patient' => $fkPatient,
				'note' => (string) (isset($data['note']) ? $data['note'] : ''),
				'lines' => array($line),
			));
			if ($rc < 0) {
				$this->error = $bill->error !== '' ? $bill->error : 'ClinicPayErrBill';
				throw new ClinicPayRefusedException($this->error);
			}

			// 2) confirm the charge (invoice + payment, same transaction)
			$rc = $bill->confirm($user, $channel, $channelRef);
			if ($rc < 0) {
				$this->error = $bill->error !== '' ? $bill->error : 'ClinicPayErrBill';
				throw new ClinicPayRefusedException($this->error);
			}

			// 3) card row + log, reserved numbering inside this transaction
			$numbering = new CardNumbering($this->db);
			$ref = $numbering->nextReference(CardNumbering::prefixFor());

			if ($isNew) {
				$dateEnd = isset($data['date_end']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $data['date_end']) ? (string) $data['date_end'] : null;
				$sql = "INSERT INTO ".$this->db->prefix()."clinicpay_card";
				$sql .= " (entity, ref, fk_patient, fk_product, card_type, total_count, used_count, total_value, used_value,";
				$sql .= " date_start, date_end, status, note, fk_user_creat, date_creation)";
				$sql .= " VALUES (".((int) $conf->entity).", '".$this->db->escape($ref)."', ".$fkPatient;
				$sql .= ", ".($fkProduct > 0 ? $fkProduct : 'NULL').", '".$this->db->escape($cardType)."'";
				$sql .= ", ".($cardType === CLINICPAY_CARD_COUNT ? $count : 0).", 0";
				$sql .= ", ".($cardType === CLINICPAY_CARD_VALUE ? price2num($value, 'MT') : 0).", 0";
				$sql .= ", '".$this->db->idate(dol_now())."', ".($dateEnd !== null ? "'".$dateEnd."'" : 'NULL');
				$sql .= ", ".CLINICPAY_CARD_VALID.", '".$this->db->escape((string) (isset($data['note']) ? $data['note'] : ''))."'";
				$sql .= ", ".((int) $user->id).", '".$this->db->idate(dol_now())."')";
				$this->query($sql);
				$this->id = (int) $this->db->last_insert_id($this->db->prefix()."clinicpay_card");
				if ($this->id <= 0) {
					throw new RuntimeException('cannot read card rowid');
				}
				$this->ref = $ref;
				$this->card_type = $cardType;
				$this->status = CLINICPAY_CARD_VALID;
				$this->insertLog(CLINICPAY_LOG_CREATE, $cardType === CLINICPAY_CARD_COUNT ? $count : 0, $cardType === CLINICPAY_CARD_VALUE ? $value : 0.0, $bill->id, $user, 'sell '.$ref);
				patient_audit($this->db, $fkPatient, 'CLINICPAY_CARD_CREATE', $user, array('op' => 'sell', 'ref' => $ref, 'card' => $this->id, 'bill' => $bill->id, 'card_type' => $cardType, 'count' => $count, 'value' => $value));
			} else {
				$sql = "UPDATE ".$this->db->prefix()."clinicpay_card SET";
				if ($cardType === CLINICPAY_CARD_COUNT) {
					$sql .= " total_count = total_count + ".$count;
				} else {
					$sql .= " total_value = total_value + ".price2num($value, 'MT');
				}
				// a topped-up card becomes usable again
				$sql .= ", status = ".CLINICPAY_CARD_VALID." WHERE rowid = ".((int) $this->id)." AND status <> ".CLINICPAY_CARD_REFUNDED;
				$resql = $this->db->query($sql);
				if (!$resql || $this->db->affected_rows($resql) !== 1) {
					throw new RuntimeException('card top-up update failed');
				}
				$this->insertLog(CLINICPAY_LOG_CHARGE, $cardType === CLINICPAY_CARD_COUNT ? $count : 0, $cardType === CLINICPAY_CARD_VALUE ? $value : 0.0, $bill->id, $user, 'charge '.$ref);
				patient_audit($this->db, $this->fk_patient, 'CLINICPAY_CARD_CHARGE', $user, array('op' => 'charge', 'ref' => $this->ref, 'card' => $this->id, 'bill' => $bill->id, 'card_type' => $cardType, 'count' => $count, 'value' => $value));
			}
			$this->bill_id = (int) $bill->id;
			$this->db->commit();
		} catch (ClinicPayRefusedException $e) {
			$this->rollbackAll();
			return -2;
		} catch (Throwable $e) {
			$this->rollbackAll();
			$this->error = $e->getMessage();
			dol_syslog('ServiceCard::sellOrCharge failed: '.$e->getMessage(), LOG_ERR);
			return -1;
		}
		return 1;
	}

	/**
	 * Consume the card (consume permission, checked by caller). No invoice:
	 * the charge was paid when the card was sold. Atomicity: a conditional
	 * UPDATE must affect exactly one row, otherwise the request is refused
	 * (concurrency-safe, spec §3.4 / §5.3). An expired card is lazily marked
	 * EXPIRE (status 2 + log) on the first consume attempt.
	 *
	 * @param	User	$user	Acting user (consume permission)
	 * @param	float	$amount	Value amount (VALUE cards only; COUNT consumes 1)
	 * @param	string	$note	Consumer note (treatment context)
	 * @return	int				1 ok, -2 refused, -1 error
	 */
	public function consume(User $user, $amount = 0.0, $note = '')
	{
		global $conf;

		$this->error = '';
		if ($this->id <= 0 || $this->fetch($this->id) <= 0) {
			$this->error = 'ClinicPayErrCardInvalid';
			return -2;
		}
		$grace = (int) getDolGlobalInt('CLINICPAY_CARD_GRACE_DAYS');
		$amount = price2num((float) $amount, 'MT');

		if ((int) $this->status === CLINICPAY_CARD_REFUNDED || (int) $this->status === CLINICPAY_CARD_EXPIRED) {
			$this->error = 'ClinicPayErrCardInvalid';
			return -2;
		}

		$this->db->begin();
		try {
			// Lazy expire: past date_end (+ grace) the card cannot be consumed.
			if ($this->isExpired($grace)) {
				$sql = "UPDATE ".$this->db->prefix()."clinicpay_card SET status = ".CLINICPAY_CARD_EXPIRED;
				$sql .= " WHERE rowid = ".((int) $this->id)." AND status = ".CLINICPAY_CARD_VALID;
				$this->query($sql);
				$this->insertLog(CLINICPAY_LOG_EXPIRE, 0, 0.0, null, $user, 'expired'.($grace > 0 ? ' (grace '.$grace.'d)' : ''));
				patient_audit($this->db, $this->fk_patient, 'CLINICPAY_CARD_EXPIRE', $user, array('op' => 'expire', 'ref' => $this->ref, 'card' => $this->id));
				$this->db->commit();
				$this->status = CLINICPAY_CARD_EXPIRED;
				$this->error = 'ClinicPayErrCardExpired';
				return -2;
			}

			if ((int) $this->status !== CLINICPAY_CARD_VALID) {
				$this->error = 'ClinicPayErrCardInvalid';
				throw new ClinicPayRefusedException($this->error);
			}

			// Atomic gate: exactly one row must move.
			if ($this->card_type === CLINICPAY_CARD_COUNT) {
				$sql = "UPDATE ".$this->db->prefix()."clinicpay_card SET used_count = used_count + 1";
				$sql .= " WHERE rowid = ".((int) $this->id)." AND status = ".CLINICPAY_CARD_VALID;
				$sql .= " AND used_count < total_count AND total_count > 0";
				$sql .= " AND (date_end IS NULL OR date_end >= DATE_SUB(CURDATE(), INTERVAL ".max(0, $grace)." DAY))";
			} else {
				if ($amount <= 0) {
					$this->error = 'ClinicPayErrCardValue';
					throw new ClinicPayRefusedException($this->error);
				}
				$sql = "UPDATE ".$this->db->prefix()."clinicpay_card SET used_value = used_value + ".price2num($amount, 'MT');
				$sql .= " WHERE rowid = ".((int) $this->id)." AND status = ".CLINICPAY_CARD_VALID;
				$sql .= " AND used_value + ".price2num($amount, 'MT')." <= total_value AND total_value > 0";
				$sql .= " AND (date_end IS NULL OR date_end >= DATE_SUB(CURDATE(), INTERVAL ".max(0, $grace)." DAY))";
			}
			$resql = $this->db->query($sql);
			if (!$resql) {
				throw new RuntimeException($this->db->lasterror());
			}
			if ($this->db->affected_rows($resql) !== 1) {
				// overdrawn, used up or lost a race -> refuse
				$this->error = $this->card_type === CLINICPAY_CARD_COUNT ? 'ClinicPayErrCardUsedUp' : 'ClinicPayErrCardOverValue';
				throw new ClinicPayRefusedException($this->error);
			}

			$this->insertLog(CLINICPAY_LOG_CONSUME, $this->card_type === CLINICPAY_CARD_COUNT ? 1 : 0, $this->card_type === CLINICPAY_CARD_VALUE ? $amount : 0.0, null, $user, (string) $note);
			patient_audit($this->db, $this->fk_patient, 'CLINICPAY_CARD_CONSUME', $user, array('op' => 'consume', 'ref' => $this->ref, 'card' => $this->id, 'card_type' => $this->card_type, 'count' => $this->card_type === CLINICPAY_CARD_COUNT ? 1 : 0, 'value' => $this->card_type === CLINICPAY_CARD_VALUE ? $amount : 0));
			$this->db->commit();
		} catch (ClinicPayRefusedException $e) {
			$this->rollbackAll();
			return -2;
		} catch (Throwable $e) {
			$this->rollbackAll();
			$this->error = $e->getMessage();
			dol_syslog('ServiceCard::consume failed: '.$e->getMessage(), LOG_ERR);
			return -1;
		}

		$this->used_count += ($this->card_type === CLINICPAY_CARD_COUNT ? 1 : 0);
		$this->used_value += ($this->card_type === CLINICPAY_CARD_VALUE ? $amount : 0);
		if ($this->card_type === CLINICPAY_CARD_COUNT && $this->used_count >= $this->total_count) {
			$sql = "UPDATE ".$this->db->prefix()."clinicpay_card SET status = ".CLINICPAY_CARD_USED." WHERE rowid = ".((int) $this->id)." AND status = ".CLINICPAY_CARD_VALID;
			$this->query($sql);
			$this->status = CLINICPAY_CARD_USED;
		}
		return 1;
	}

	/**
	 * Card refund, step 1 (write permission, checked by caller). V0.2:
	 * multi-charge cards are accepted. The credit amount is the remaining
	 * balance, spread proportionally over the sell/charge invoices
	 * (rest / total x invoice amount, rounding residual on the last one);
	 * a zero balance is refused. Each affected bill gets its own credit
	 * note draft through the two-person bill refund flow.
	 *
	 * @param	User	$user	Acting user (write permission)
	 * @param	string	$reason	Refund reason
	 * @return	int				1 ok, -2 refused, -1 error
	 */
	public function createRefund(User $user, $reason)
	{
		$this->error = '';
		$reason = trim((string) $reason);
		if ($this->id <= 0 || $this->fetch($this->id) <= 0) {
			$this->error = 'ClinicPayErrCardInvalid';
			return -2;
		}
		if (!in_array((int) $this->status, array(CLINICPAY_CARD_VALID, CLINICPAY_CARD_USED), true)) {
			$this->error = 'ClinicPayErrCardRefundState';
			return -2;
		}
		if ($reason === '') {
			$this->error = 'ClinicPayErrReason';
			return -2;
		}
		// Remaining balance / total intake; V0.1 trick of charging a bill
		// per top-up means total_* already holds the full intake.
		if ($this->card_type === CLINICPAY_CARD_COUNT) {
			$rest = max(0, (int) $this->total_count - (int) $this->used_count);
			$intake = (int) $this->total_count;
		} else {
			$rest = max(0, price2num((float) $this->total_value - (float) $this->used_value, 'MT'));
			$intake = (float) $this->total_value;
		}
		if ($rest <= 0 || $intake <= 0) {
			$this->error = 'ClinicPayErrCardNothingToRefund';
			return -2;
		}
		$billIds = $this->relatedBillIds();
		if (empty($billIds)) {
			$this->error = 'ClinicPayErrNoBill';
			return -2;
		}

		// Proportional spread, cent-rounded, residual on the last bill.
		$rate = $rest / $intake;
		$amounts = array();
		$sumBase = 0.0;
		foreach ($billIds as $bid) {
			$b = new Paybill($this->db);
			if ($b->fetch($bid) <= 0) {
				$this->error = 'ClinicPayErrNoBill';
				return -2;
			}
			$sumBase += price2num((float) $b->amount_total, 'MT');
			$amounts[$bid] = price2num((float) $b->amount_total, 'MT') * $rate;
		}
		$sum = 0.0;
		foreach ($amounts as $bid => $a) {
			$amounts[$bid] = price2num($a, 'MT');
			$sum += $amounts[$bid];
		}
		$target = price2num($sumBase * $rate, 'MT');
		$lastBid = null;
		foreach ($billIds as $bid) {
			if ($amounts[$bid] > 0) {
				$lastBid = $bid;
			}
		}
		if ($lastBid !== null) {
			$amounts[$lastBid] = price2num($amounts[$lastBid] + ($target - $sum), 'MT');
			if ($amounts[$lastBid] < 0) {
				$amounts[$lastBid] = 0;
			}
		}

		$this->db->begin();
		try {
			$drafts = 0;
			foreach ($billIds as $bid) {
				if ($amounts[$bid] < 0.01) {
					continue;
				}
				$bill = new Paybill($this->db);
				if ($bill->fetch($bid) <= 0) {
					$this->error = 'ClinicPayErrNoBill';
					throw new ClinicPayRefusedException($this->error);
				}
				$rc = $bill->createRefundDraft($user, 'Refund card '.$this->ref.': '.$reason, $amounts[$bid]);
				if ($rc < 0) {
					$this->error = $bill->error !== '' ? $bill->error : 'ClinicPayErrRefundDraft';
					throw new ClinicPayRefusedException($this->error);
				}
				$drafts++;
			}
			if ($drafts === 0) {
				$this->error = 'ClinicPayErrCardNothingToRefund';
				throw new ClinicPayRefusedException($this->error);
			}
			$this->bill_id = (int) $billIds[0];
			patient_audit($this->db, $this->fk_patient, 'CLINICPAY_CARD_REFUND', $user, array('op' => 'draft', 'ref' => $this->ref, 'card' => $this->id, 'bills' => $billIds, 'amounts' => $amounts, 'rest' => $rest));
			$this->db->commit();
		} catch (ClinicPayRefusedException $e) {
			$this->rollbackAll();
			return -2;
		} catch (Throwable $e) {
			$this->rollbackAll();
			$this->error = $e->getMessage();
			dol_syslog('ServiceCard::createRefund failed: '.$e->getMessage(), LOG_ERR);
			return -1;
		}
		return 1;
	}

	/**
	 * Card refund, step 2 (validate permission, checked by caller). The
	 * two-person red line is enforced inside Paybill::executeRefund() for
	 * every pending credit note. On success the card is zeroed (status 3)
	 * and a REFUND log is appended, in the same transaction as the bill
	 * refunds.
	 *
	 * @param	User	$user	Acting user (validate permission)
	 * @return	int				1 ok, -2 refused, -1 error
	 */
	public function executeRefund(User $user)
	{
		$this->error = '';
		if ($this->id <= 0 || $this->fetch($this->id) <= 0) {
			$this->error = 'ClinicPayErrCardInvalid';
			return -2;
		}
		if (!in_array((int) $this->status, array(CLINICPAY_CARD_VALID, CLINICPAY_CARD_USED), true)) {
			$this->error = 'ClinicPayErrCardRefundState';
			return -2;
		}
		$billIds = $this->relatedBillIds();
		if (empty($billIds)) {
			$this->error = 'ClinicPayErrNoBill';
			return -2;
		}

		$this->db->begin();
		try {
			$executed = 0;
			$refundedTotal = 0.0;
			foreach ($billIds as $bid) {
				$bill = new Paybill($this->db);
				if ($bill->fetch($bid) <= 0) {
					$this->error = 'ClinicPayErrNoBill';
					throw new ClinicPayRefusedException($this->error);
				}
				if ($bill->findRefundDraft() <= 0) {
					continue;
				}
				$rc = $bill->executeRefund($user);
				if ($rc < 0) {
					$this->error = $bill->error !== '' ? $bill->error : 'ClinicPayErrRefund';
					throw new ClinicPayRefusedException($this->error);
				}
				$executed++;
			}
			if ($executed === 0) {
				$this->error = 'ClinicPayErrNoRefundDraft';
				throw new ClinicPayRefusedException($this->error);
			}

			$sql = "UPDATE ".$this->db->prefix()."clinicpay_card SET status = ".CLINICPAY_CARD_REFUNDED;
			$sql .= " WHERE rowid = ".((int) $this->id)." AND status IN (".CLINICPAY_CARD_VALID.", ".CLINICPAY_CARD_USED.")";
			$resql = $this->db->query($sql);
			if (!$resql || $this->db->affected_rows($resql) !== 1) {
				throw new RuntimeException('card refund update failed');
			}
			$restCount = max(0, (int) $this->total_count - (int) $this->used_count);
			$restValue = max(0, (float) $this->total_value - (float) $this->used_value);
			$this->insertLog(CLINICPAY_LOG_REFUND, -1 * $restCount, -1 * $restValue, $billIds[0], $user, 'card refunded ('.$executed.' credit note(s))');
			patient_audit($this->db, $this->fk_patient, 'CLINICPAY_CARD_REFUND', $user, array('op' => 'refund', 'ref' => $this->ref, 'card' => $this->id, 'bills' => $billIds, 'executed' => $executed, 'rest_count' => $restCount, 'rest_value' => $restValue));
			$this->db->commit();
		} catch (ClinicPayRefusedException $e) {
			$this->rollbackAll();
			return -2;
		} catch (Throwable $e) {
			$this->rollbackAll();
			$this->error = $e->getMessage();
			dol_syslog('ServiceCard::executeRefund failed: '.$e->getMessage(), LOG_ERR);
			return -1;
		}
		$this->status = CLINICPAY_CARD_REFUNDED;
		return 1;
	}

	/**
	 * @return	int	1 ok, -1 error
	 */
	public function fetchLogs()
	{
		$this->logs = array();
		$sql = "SELECT rowid, op, count_delta, value_delta, fk_bill, fk_user, note, date_creation";
		$sql .= " FROM ".$this->db->prefix()."clinicpay_card_log";
		$sql .= " WHERE fk_card = ".((int) $this->id);
		$sql .= $this->db->order('rowid', 'DESC');
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		while ($o = $this->db->fetch_object($resql)) {
			$this->logs[] = array(
				'id' => (int) $o->rowid,
				'op' => $o->op,
				'count_delta' => (int) $o->count_delta,
				'value_delta' => (float) $o->value_delta,
				'fk_bill' => $o->fk_bill !== null ? (int) $o->fk_bill : null,
				'fk_user' => $o->fk_user !== null ? (int) $o->fk_user : null,
				'note' => (string) $o->note,
				'date' => $this->db->jdate($o->date_creation),
			);
		}
		$this->db->free($resql);
		return 1;
	}

	/**
	 * Expired check with the grace days (read helper; the status is only
	 * flipped lazily on the first consume attempt).
	 *
	 * @param	int	$graceDays	CLINICPAY_CARD_GRACE_DAYS
	 * @return	bool
	 */
	public function isExpired($graceDays = 0)
	{
		if ($this->date_end === null || $this->date_end === '') {
			return false;
		}
		$end = strtotime((string) $this->date_end.' 23:59:59');
		$limit = time() - 86400 * max(0, (int) $graceDays);
		return $end < $limit;
	}

	/**
	 * Whether a pending bill refund draft exists on any sell/charge bill of
	 * this card (card page shows the "execute refund" entry).
	 *
	 * @return	bool
	 */
	public function hasPendingRefund()
	{
		foreach ($this->relatedBillIds() as $bid) {
			$bill = new Paybill($this->db);
			if ($bill->fetch($bid) <= 0) {
				continue;
			}
			if ($bill->findRefundDraft() > 0) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @return	array{total:int,rows:array<int,object>}|null
	 */
	public function searchLogs($limit = 200, $offset = 0)
	{
		$sql = "SELECT COUNT(*) as n FROM ".$this->db->prefix()."clinicpay_card_log WHERE fk_card = ".((int) $this->id);
		$resql = $this->db->query($sql);
		if (!$resql) {
			return null;
		}
		$total = (int) $this->db->fetch_object($resql)->n;
		$sql = "SELECT rowid, op, count_delta, value_delta, fk_bill, fk_user, note, date_creation";
		$sql .= " FROM ".$this->db->prefix()."clinicpay_card_log";
		$sql .= " WHERE fk_card = ".((int) $this->id);
		$sql .= $this->db->order('rowid', 'DESC');
		$sql .= $this->db->plimit((int) $limit, (int) $offset);
		$resql = $this->db->query($sql);
		if (!$resql) {
			return null;
		}
		$rows = array();
		while ($o = $this->db->fetch_object($resql)) {
			$rows[] = $o;
		}
		$this->db->free($resql);
		return array('total' => $total, 'rows' => $rows);
	}

	// ----------------------------------------------------------------- //

	/** @var array<int,array> Card movement log (fetchLogs) */
	public $logs = array();

	/** @var int Charge bill rowid of the last sell/charge (result field) */
	public $bill_id = 0;

	/**
	 * @return	void
	 */
	private function insertLog($op, $countDelta, $valueDelta, $fkBill, User $user, $note = '')
	{
		$sql = "INSERT INTO ".$this->db->prefix()."clinicpay_card_log";
		$sql .= " (fk_card, op, count_delta, value_delta, fk_bill, fk_user, note, date_creation)";
		$sql .= " VALUES (".((int) $this->id).", '".$this->db->escape($op)."', ".((int) $countDelta);
		$sql .= ", ".price2num($valueDelta, 'MT').", ".($fkBill !== null ? (int) $fkBill : 'NULL');
		$sql .= ", ".((int) $user->id).", '".$this->db->escape((string) $note)."', '".$this->db->idate(dol_now())."')";
		$this->query($sql);
	}

	/**
	 * Bill rowids backing this card: the sell bill plus every top-up bill
	 * (CREATE + CHARGE logs), oldest first, deduplicated.
	 *
	 * @return	array<int,int>
	 */
	public function relatedBillIds()
	{
		$sql = "SELECT DISTINCT l.fk_bill";
		$sql .= " FROM ".$this->db->prefix()."clinicpay_card_log as l";
		$sql .= " WHERE l.fk_card = ".((int) $this->id)." AND l.fk_bill IS NOT NULL";
		$sql .= " AND l.op IN ('".CLINICPAY_LOG_CREATE."', '".CLINICPAY_LOG_CHARGE."')";
		$sql .= $this->db->order('l.fk_bill', 'ASC');
		$resql = $this->db->query($sql);
		if (!$resql) {
			return array();
		}
		$ids = array();
		while ($o = $this->db->fetch_object($resql)) {
			$ids[] = (int) $o->fk_bill;
		}
		$this->db->free($resql);
		return $ids;
	}

	/**
	 * ref of a product rowid ('' if unknown), for card line labels.
	 *
	 * @param	int	$fkProduct	Product rowid
	 * @return	string
	 */
	private function productRef($fkProduct)
	{
		$resql = $this->db->query("SELECT ref FROM ".$this->db->prefix()."product WHERE rowid = ".((int) $fkProduct));
		if (!$resql) {
			return '';
		}
		$obj = $this->db->fetch_object($resql);
		$this->db->free($resql);
		return $obj ? (string) $obj->ref : '';
	}

	/** @param string $sql Execute or throw */
	private function query($sql)
	{
		$resql = $this->db->query($sql);
		if (!$resql) {
			throw new RuntimeException($this->db->lasterror());
		}
		return $resql;
	}

	/** Fully unwind DoliDB's transaction depth (nested begin/commit are depth-counted). */
	private function rollbackAll()
	{
		while (property_exists($this->db, 'transaction_opened') && $this->db->transaction_opened > 0) {
			$this->db->rollback();
		}
	}
}
