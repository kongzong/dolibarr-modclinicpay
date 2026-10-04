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
 * \file    htdocs/custom/clinicpay/class/paybill.class.php
 * \ingroup clinicpay
 * \brief   One charge bill (spec §3.3). Never deleted; a refunded bill stays
 *          as the trace of its credit note. Phase 1 skeleton: fetch / search /
 *          lines. The confirm (invoice creation on the same transaction,
 *          idempotent) and refund transactions land in phase 2.
 */

dol_include_once('/clinicpay/lib/clinicpay.lib.php');
dol_include_once('/clinicpay/class/paybillnumbering.class.php');
dol_include_once('/patient/lib/patient.lib.php');
require_once DOL_DOCUMENT_ROOT.'/core/lib/price.lib.php';
require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/paiement/class/paiement.class.php';

/**
 * Business refusal raised inside an open transaction; rolled back and
 * reported as return code -2 with $this->error already set.
 */
class ClinicPayRefusedException extends RuntimeException
{
}

/**
 * Class Paybill
 */
class Paybill extends CommonObject
{
	/** @var string Element type (for hooks / REST) */
	public $element = 'clinicpay_bill';

	/** @var string */
	public $table_element = 'clinicpay_bill';

	/** @var int */
	public $id;

	public $entity;
	public $ref;
	/** @var int Patient profile rowid */
	public $fk_patient;
	/** @var int|null Visit (medrecord) rowid; set on a consultation charge, null for Rx-only / retail / recharge */
	public $fk_medrecord;
	/** @var int|null Native invoice rowid (null until confirm) */
	public $fk_invoice;
	/** @var int 0 draft, 1 paid, 9 refunded */
	public $status = CLINICPAY_BILL_DRAFT;
	/** @var float Snapshot total taken through calcul_price_total() */
	public $amount_total;
	/** @var string CASH / SCAN */
	public $channel;
	/** @var string Scan reference */
	public $channel_ref;
	/** @var string Tax invoice number written back from the tax system (not a tax integration) */
	public $fapiao_no;
	/** @var int|null */
	public $fk_user_pay;
	/** @var int|null Unix timestamp */
	public $date_pay;
	public $note;
	public $model_pdf;
	/** @var string Relative path of the last generated PDF */
	public $last_main_doc;
	/** @var int */
	public $fk_user_creat;
	/** @var int Unix timestamp */
	public $date_creation;

	/** @var string Patient name filled on fetch */
	public $patient_name;
	/** @var string Patient card no filled on fetch */
	public $card_no;
	/** @var string Cashier name filled for the PDF (fk_user_pay) */
	public $cashier_name;

	/**
	 * Bill lines: {fk_product, product_ref, label, qty, price_unit,
	 * vat_rate, subprice_total, fk_prescription, fk_dispense}
	 * @var array<int,array>
	 */
	public $lines = array();

	/** @var string Last error (translated key or db error) */
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
		$sql = "SELECT b.rowid, b.entity, b.ref, b.fk_patient, b.fk_medrecord, b.fk_invoice, b.status, b.amount_total,";
		$sql .= " b.channel, b.channel_ref, b.fk_user_pay, b.date_pay, b.note, b.model_pdf, b.last_main_doc,";
		$sql .= " b.fk_user_creat, b.date_creation, b.fapiao_no";
		$sql .= " FROM ".$this->db->prefix()."clinicpay_bill as b";
		$sql .= " WHERE b.rowid = ".((int) $id);
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
		$this->fk_medrecord = $obj->fk_medrecord !== null ? (int) $obj->fk_medrecord : null;
		$this->fk_invoice = $obj->fk_invoice !== null ? (int) $obj->fk_invoice : null;
		$this->status = (int) $obj->status;
		$this->amount_total = (float) $obj->amount_total;
		$this->channel = $obj->channel;
		$this->channel_ref = $obj->channel_ref;
		$this->fapiao_no = (string) $obj->fapiao_no;
		$this->fk_user_pay = $obj->fk_user_pay !== null ? (int) $obj->fk_user_pay : null;
		$this->date_pay = $obj->date_pay ? $this->db->jdate($obj->date_pay) : null;
		$this->note = (string) $obj->note;
		$this->model_pdf = (string) $obj->model_pdf;
		$this->last_main_doc = isset($obj->last_main_doc) ? (string) $obj->last_main_doc : '';
		$this->fk_user_creat = (int) $obj->fk_user_creat;
		$this->date_creation = $this->db->jdate($obj->date_creation);
		return $this->fetchLines();
	}

	/**
	 * @return	int	1 ok, -1 error
	 */
	public function fetchLines()
	{
		$this->lines = array();
		$sql = "SELECT rowid, fk_product, product_ref, label, qty, price_unit, vat_rate, subprice_total, fk_prescription, fk_dispense";
		$sql .= " FROM ".$this->db->prefix()."clinicpay_bill_line";
		$sql .= " WHERE fk_bill = ".((int) $this->id);
		$sql .= $this->db->order('position', 'ASC');
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		while ($o = $this->db->fetch_object($resql)) {
			$this->lines[] = array(
				'id' => (int) $o->rowid,
				'fk_product' => $o->fk_product !== null ? (int) $o->fk_product : null,
				'product_ref' => $o->product_ref,
				'label' => $o->label,
				'qty' => (float) $o->qty,
				'price_unit' => (float) $o->price_unit,
				'vat_rate' => (float) $o->vat_rate,
				'subprice_total' => (float) $o->subprice_total,
				'fk_prescription' => $o->fk_prescription !== null ? (int) $o->fk_prescription : null,
				'fk_dispense' => $o->fk_dispense !== null ? (int) $o->fk_dispense : null,
			);
		}
		$this->db->free($resql);
		return 1;
	}

	/**
	 * Paged list. Refunded bills hidden unless a specific status is requested.
	 *
	 * @param	array	$f		Filters: q, status (-1 = non-refunded), from, to, fk_patient
	 * @param	int		$limit	Page size
	 * @param	int		$offset	Offset
	 * @return	array{total:int,rows:array<int,object>}|null
	 */
	public function search(array $f, $limit = 25, $offset = 0)
	{
		global $conf;

		$from = " FROM ".$this->db->prefix()."clinicpay_bill as b";
		$from .= " INNER JOIN ".$this->db->prefix()."patient_profile as pp ON pp.rowid = b.fk_patient";
		$from .= " INNER JOIN ".$this->db->prefix()."societe as s ON s.rowid = pp.fk_soc";
		$where = " WHERE b.entity = ".((int) $conf->entity);
		if (!empty($f['q'])) {
			$like = "'%".$this->db->escape(trim($f['q']))."%'";
			$where .= " AND (b.ref LIKE ".$like." OR pp.card_no LIKE ".$like." OR s.nom LIKE ".$like.")";
		}
		if (isset($f['status']) && (int) $f['status'] >= 0) {
			$where .= " AND b.status = ".((int) $f['status']);
		} else {
			$where .= " AND b.status <> ".CLINICPAY_BILL_REFUNDED;
		}
		// The list dates bills on date_creation by default; the dashboard
		// charts revenue on date_pay, so a drill-down passes date_field to
		// keep the two views agreeing.
		$dateField = (isset($f['date_field']) && $f['date_field'] === 'date_pay') ? 'b.date_pay' : 'b.date_creation';
		if (!empty($f['from'])) {
			$where .= " AND ".$dateField." >= '".$this->db->idate((int) $f['from'])."'";
		}
		if (!empty($f['to'])) {
			$where .= " AND ".$dateField." <= '".$this->db->idate((int) $f['to'])."'";
		}
		if (!empty($f['fk_patient'])) {
			$where .= " AND b.fk_patient = ".((int) $f['fk_patient']);
		}
		if (isset($f['channel']) && (string) $f['channel'] !== '') {
			$where .= " AND b.channel = '".$this->db->escape((string) $f['channel'])."'";
		}

		$sql = "SELECT COUNT(*) as n".$from.$where;
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return null;
		}
		$total = (int) $this->db->fetch_object($resql)->n;

		$select = "SELECT b.rowid, b.ref, b.fk_patient, b.status, b.amount_total, b.channel, b.date_pay, b.date_creation, b.fapiao_no,";
		$select .= " pp.card_no, s.nom as patient_name";
		$sql = $select.$from.$where;
		$sql .= $this->db->order('b.rowid', 'DESC');
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
	 * Charge bills of one patient, newest first.
	 *
	 * @param	DoliDB	$db			Database handler
	 * @param	int		$fkPatient	Patient profile rowid
	 * @param	int		$limit		Max rows
	 * @return	array<int,object>	Rows
	 */
	public static function fetchAllByPatient($db, $fkPatient, $limit = 50)
	{
		return clinicpay_bill_list_by_patient($db, $fkPatient, $limit);
	}

	// ================================================================= //
	// Phase 2: charge core (spec §3.3)                                   //
	// ================================================================= //

	/**
	 * Create a draft bill with price-snapshot lines inside one transaction
	 * (daily numbering reserved in the same transaction).
	 *
	 * @param	User	$user		Acting user (write permission)
	 * @param	array	$data		{fk_patient:int, note:string, lines:array<{fk_product:int,qty:float}|{label:string,price_unit:float,vat_rate?:float}>}
	 * @return	int					1 ok, -2 refused (validation), -1 error (this->error)
	 */
	public function create(User $user, array $data)
	{
		global $conf;
		$this->error = '';
		$fkPatient = isset($data['fk_patient']) ? (int) $data['fk_patient'] : 0;
		if ($fkPatient <= 0) {
			$this->error = 'ClinicPayErrPatient';
			return -2;
		}
		$fkMedrecord = isset($data['fk_medrecord']) ? (int) $data['fk_medrecord'] : 0;
		$inputLines = isset($data['lines']) && is_array($data['lines']) ? $data['lines'] : array();
		if (count($inputLines) < 1) {
			$this->error = 'ClinicPayErrNoLines';
			return -2;
		}

		$this->db->begin();
		try {
			$soc = $this->patientSocId($fkPatient);
			if ($soc < 0) {
				$this->error = 'ClinicPayErrPatient';
				throw new ClinicPayRefusedException($this->error);
			}

			$lines = $this->snapshotLines($inputLines);
			if (count($lines) < 1) {
				$this->error = 'ClinicPayErrNoLines';
				throw new ClinicPayRefusedException($this->error);
			}
			$total = 0.0;
			foreach ($lines as $l) {
				$total += $l['subprice_total'];
			}

			$numbering = new PaybillNumbering($this->db);
			$ref = $numbering->nextReference(PaybillNumbering::prefixFor());

			$sql = "INSERT INTO ".$this->db->prefix()."clinicpay_bill";
			$sql .= " (entity, ref, fk_patient, fk_medrecord, status, amount_total, note, fk_user_creat, date_creation)";
			$sql .= " VALUES (".((int) $conf->entity).", '".$this->db->escape($ref)."', ".$fkPatient.", ".($fkMedrecord > 0 ? (int) $fkMedrecord : 'NULL').", ".CLINICPAY_BILL_DRAFT;
			$sql .= ", ".price2num($total, 'MT').", '".$this->db->escape((string) (isset($data['note']) ? $data['note'] : ''))."', ".((int) $user->id);
			$sql .= ", '".$this->db->idate(dol_now())."')";
			$this->query($sql);
			$this->id = (int) $this->db->last_insert_id($this->db->prefix()."clinicpay_bill");
			if ($this->id <= 0) {
				throw new RuntimeException('cannot read bill rowid');
			}
			$this->ref = $ref;
			$this->amount_total = $total;
			$this->replaceLines($lines);
			patient_audit($this->db, $fkPatient, 'CLINICPAY_BILL', $user, array('op' => 'create', 'ref' => $ref, 'bill' => $this->id, 'amount' => $total, 'lines' => count($lines)));
			$this->db->commit();
		} catch (ClinicPayRefusedException $e) {
			$this->rollbackAll();
			return -2;
		} catch (Throwable $e) {
			$this->rollbackAll();
			$this->error = $e->getMessage();
			dol_syslog('Paybill::create failed: '.$e->getMessage(), LOG_ERR);
			return -1;
		}
		return 1;
	}

	/**
	 * Replace draft lines / note (write, draft only). The bill record itself
	 * is never deleted; its draft lines are re-snapshotted.
	 *
	 * @param	User	$user		Acting user (write permission, checked by caller)
	 * @param	array	$lines		Same shape as create()
	 * @param	string|null	$note	New note (null = keep)
	 * @return	int					1 ok, -2 refused (not draft), -1 error
	 */
	public function setLines(User $user, array $lines, $note = null)
	{
		$this->error = '';
		if ($this->id <= 0 || $this->fetch($this->id) <= 0) {
			$this->error = 'ClinicPayErrNotDraft';
			return -2;
		}
		if ((int) $this->status !== CLINICPAY_BILL_DRAFT) {
			$this->error = 'ClinicPayErrNotDraft';
			return -2;
		}
		if (count($lines) < 1) {
			$this->error = 'ClinicPayErrNoLines';
			return -2;
		}
		$this->db->begin();
		try {
			$snap = $this->snapshotLines($lines);
			if (count($snap) < 1) {
				$this->error = 'ClinicPayErrNoLines';
				throw new ClinicPayRefusedException($this->error);
			}
			$total = 0.0;
			foreach ($snap as $l) {
				$total += $l['subprice_total'];
			}
			$this->replaceLines($snap);
			$sql = "UPDATE ".$this->db->prefix()."clinicpay_bill SET amount_total = ".price2num($total, 'MT');
			if ($note !== null) {
				$sql .= ", note = '".$this->db->escape((string) $note)."'";
			}
			$sql .= " WHERE rowid = ".((int) $this->id);
			$this->query($sql);
			$this->amount_total = $total;
			patient_audit($this->db, $this->fk_patient, 'CLINICPAY_BILL', $user, array('op' => 'edit', 'ref' => $this->ref, 'bill' => $this->id, 'amount' => $total));
			$this->db->commit();
		} catch (ClinicPayRefusedException $e) {
			$this->rollbackAll();
			return -2;
		} catch (Throwable $e) {
			$this->rollbackAll();
			$this->error = $e->getMessage();
			dol_syslog('Paybill::setLines failed: '.$e->getMessage(), LOG_ERR);
			return -1;
		}
		return 1;
	}

	/**
	 * Confirm the charge (pay permission, checked by caller), one transaction:
	 * idempotent gate -> native Facture -> validate -> Paiement -> write back
	 * -> audit. Any failure rolls the whole transaction back (red line: no
	 * invoice without a bill, no bill without an invoice).
	 *
	 * @param	User	$user			Acting user (pay permission)
	 * @param	string	$channel		CASH / SCAN
	 * @param	string	$channelRef		Scan reference (required for SCAN)
	 * @return	int						1 ok (idempotent included), -2 refused, -1 error
	 */
	public function confirm(User $user, $channel, $channelRef)
	{
		$this->error = '';
		if ($this->id <= 0 || $this->fetch($this->id) <= 0) {
			$this->error = 'ClinicPayErrNotDraft';
			return -2;
		}
		if ((int) $this->status === CLINICPAY_BILL_PAID) {
			return 1; // idempotent: already confirmed, no second invoice
		}
		if ((int) $this->status === CLINICPAY_BILL_REFUNDED) {
			$this->error = 'ClinicPayErrRefunded';
			return -2;
		}
		if ((int) $this->status !== CLINICPAY_BILL_DRAFT) {
			$this->error = 'ClinicPayErrNotDraft';
			return -2;
		}
		$channel = strtoupper((string) $channel);
		if (!in_array($channel, array(CLINICPAY_CHANNEL_CASH, CLINICPAY_CHANNEL_SCAN), true)) {
			$this->error = 'ClinicPayErrChannel';
			return -2;
		}
		$channelRef = trim((string) $channelRef);
		if ($channel === CLINICPAY_CHANNEL_SCAN && $channelRef === '') {
			$this->error = 'ClinicPayErrRefRequired';
			return -2;
		}

		$this->db->begin();
		try {
			$soc = $this->patientSocId($this->fk_patient);
			if ($soc <= 0) {
				$this->error = 'ClinicPayErrNoSoc';
				throw new ClinicPayRefusedException($this->error);
			}

			// Idempotency gate: exactly one writer flips 0 -> 1.
			$sql = "UPDATE ".$this->db->prefix()."clinicpay_bill SET status = ".CLINICPAY_BILL_PAID;
			$sql .= ", channel = '".$this->db->escape($channel)."'";
			$sql .= ", channel_ref = '".$this->db->escape($channelRef)."'";
			$sql .= ", fk_user_pay = ".((int) $user->id).", date_pay = '".$this->db->idate(dol_now())."'";
			$sql .= " WHERE rowid = ".((int) $this->id)." AND status = ".CLINICPAY_BILL_DRAFT;
			$resql = $this->db->query($sql);
			if (!$resql) {
				throw new RuntimeException($this->db->lasterror());
			}
			if ($this->db->affected_rows($resql) < 1) {
				// A concurrent writer confirmed between our fetch and the gate.
				$this->db->rollback();
				$this->status = CLINICPAY_BILL_PAID;
				return 1;
			}

			// Native invoice, same transaction (nested begin/commit are
			// depth-counted by DoliDB; we commit/rollback at the top).
			require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
			$facture = new Facture($this->db);
			$facture->socid = $soc;
			$facture->type = Facture::TYPE_STANDARD;
			$facture->date = dol_now();
			$facture->cond_reglement_id = $this->defaultPaymentTermId();
			$facture->mode_reglement_id = $this->paymentModeId($channel);
			$facture->note_public = 'ClinicPay bill '.$this->ref;
			$facture->lines = array();
			foreach ($this->lines as $l) {
				$facture->lines[] = array(
					'desc' => $l['label'],
					'subprice' => price2num($l['price_unit'], 'MU'),
					'qty' => price2num($l['qty'], 'MS'),
					'tva_tx' => (float) $l['vat_rate'],
					'localtax1_tx' => 0,
					'localtax2_tx' => 0,
					'fk_product' => $l['fk_product'] !== null ? (int) $l['fk_product'] : 0,
					'remise_percent' => 0,
					'price_base_type' => 'HT',
					'info_bits' => 0,
					'fk_remise_except' => 0,
					'fk_code_ventilation' => 0,
					'fk_parent_line' => 0,
					'special_code' => 0,
					'product_type' => $l['product_type'],
					'fk_unit' => null,
					'date_start' => null,
					'date_end' => null,
					'ventilation' => 0,
					'subprice_remise' => 0,
					'vat_src_code' => '',
					'ref_ext' => '',
					'origin_id' => null,
					'origin_type' => null,
				);
			}
			$invoiceId = $facture->create($user);
			if ($invoiceId <= 0) {
				throw new RuntimeException('invoice create failed: '.($facture->error !== '' ? $facture->error : $this->db->lasterror()));
			}
			if ($facture->validate($user) <= 0) {
				throw new RuntimeException('invoice validate failed: '.($facture->error !== '' ? $facture->error : $this->db->lasterror()));
			}

			// Payment registration (native, closes the invoice).
			require_once DOL_DOCUMENT_ROOT.'/compta/paiement/class/paiement.class.php';
			$paiement = new Paiement($this->db);
			$paiement->datepaye = dol_now();
			$paiement->amounts = array((int) $facture->id => price2num($facture->total_ttc, 'MT'));
			$paiement->paiementcode = clinicpay_paiement_code($channel);
			$paiement->num_payment = $channelRef !== '' ? $channelRef : $this->ref;
			$paiement->note_public = 'ClinicPay bill '.$this->ref;
			$payId = $paiement->create($user, 1);
			if ($payId < 0) {
				throw new RuntimeException('payment create failed: '.($paiement->error !== '' ? $paiement->error : $this->db->lasterror()));
			}
			$bankAccount = (int) getDolGlobalInt('CLINICPAY_BANK_ACCOUNT');
			if ($bankAccount > 0) {
				$bankRes = $paiement->addPaymentToBank($user, 'payment', '(InvoicePayment)', $bankAccount, '', '');
				if ($bankRes < 0) {
					throw new RuntimeException('bank write failed: '.($paiement->error !== '' ? $paiement->error : $this->db->lasterror()));
				}
			}

			$sql = "UPDATE ".$this->db->prefix()."clinicpay_bill SET fk_invoice = ".((int) $facture->id)." WHERE rowid = ".((int) $this->id);
			$this->query($sql);
			patient_audit($this->db, $this->fk_patient, 'CLINICPAY_BILL', $user, array('op' => 'confirm', 'ref' => $this->ref, 'bill' => $this->id, 'invoice' => (int) $facture->id, 'amount' => (float) $facture->total_ttc, 'channel' => $channel));
			$this->db->commit();
		} catch (ClinicPayRefusedException $e) {
			$this->rollbackAll();
			return -2;
		} catch (Throwable $e) {
			$this->rollbackAll();
			$this->error = $e->getMessage();
			dol_syslog('Paybill::confirm failed: '.$e->getMessage(), LOG_ERR);
			return -1;
		}
		$this->status = CLINICPAY_BILL_PAID;
		$this->fk_invoice = (int) $facture->id;
		$this->channel = $channel;
		$this->channel_ref = $channelRef;
		$this->date_pay = dol_now();
		$this->fk_user_pay = (int) $user->id;

		// Auto-generate the charge-bill PDF (spec §3.3 step 2, optional
		// artifact). A failure here must not fail the charge itself: the
		// invoice has already been created and committed; the PDF can be
		// regenerated from the pdf.php page.
		$this->generateDocument();
		return 1;
	}

	/**
	 * Create the refund draft (write, checked by caller): a native credit
	 * note draft (TYPE_CREDIT_NOTE, fk_facture_source = original invoice)
	 * mirroring the bill lines negated. Not executed yet.
	 *
	 * V0.2: $amountTtc > 0 creates a PARTIAL credit note (one flat line of
	 * that TTC amount, VAT rate from the first bill line) - used by the
	 * card refund to credit only the remaining balance. 0 keeps the V0.1
	 * full-bill behaviour.
	 *
	 * @param	User	$user		Acting user (write permission)
	 * @param	string	$reason		Refund reason
	 * @param	float	$amountTtc	>0 = partial credit amount (TTC), 0 = full bill
	 * @return	int					1 ok (sets ->refund_draft_id), -2 refused, -1 error
	 */
	public function createRefundDraft(User $user, $reason, $amountTtc = 0.0)
	{
		$this->error = '';
		$reason = trim((string) $reason);
		if ($this->id <= 0 || $this->fetch($this->id) <= 0) {
			$this->error = 'ClinicPayErrNotPaid';
			return -2;
		}
		if ((int) $this->status !== CLINICPAY_BILL_PAID || (int) $this->fk_invoice <= 0) {
			$this->error = 'ClinicPayErrNotPaid';
			return -2;
		}
		if ($reason === '') {
			$this->error = 'ClinicPayErrReason';
			return -2;
		}
		if ($this->findRefundDraft() > 0) {
			$this->error = 'ClinicPayErrRefundDraftExists';
			return -2;
		}

		$this->db->begin();
		try {
			require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
			$orig = new Facture($this->db);
			if ($orig->fetch((int) $this->fk_invoice) <= 0) {
				throw new RuntimeException('original invoice not found');
			}
			if ((int) $orig->type !== Facture::TYPE_STANDARD || (int) $orig->status >= Facture::STATUS_ABANDONED) {
				// replaced or abandoned invoices cannot be credited
				$this->error = 'ClinicPayErrInvoiceDead';
				throw new ClinicPayRefusedException($this->error);
			}
			$credited = $this->creditedTotal((int) $this->fk_invoice);
			$partial = ($amountTtc > 0);
			$amountTtc = $partial ? price2num((float) $amountTtc, 'MT') : 0.0;
			if ($partial && $credited + $amountTtc > (float) $orig->total_ttc + 0.005) {
				// cannot credit more than the not-yet-credited remainder
				$this->error = 'ClinicPayErrAlreadyRefunded';
				throw new ClinicPayRefusedException($this->error);
			}
			if ($partial && $amountTtc < 0.01) {
				$this->error = 'ClinicPayErrAlreadyRefunded';
				throw new ClinicPayRefusedException($this->error);
			}
			if (!$partial && $credited + 0.001 >= (float) $orig->total_ttc) {
				$this->error = 'ClinicPayErrAlreadyRefunded';
				throw new ClinicPayRefusedException($this->error);
			}

			$credit = new Facture($this->db);
			$credit->socid = (int) $orig->socid;
			$credit->type = Facture::TYPE_CREDIT_NOTE;
			$credit->fk_facture_source = (int) $orig->id;
			$credit->date = dol_now();
			$credit->cond_reglement_id = (int) $orig->cond_reglement_id;
			$credit->mode_reglement_id = (int) $orig->mode_reglement_id;
			$credit->note_private = 'Refund draft of bill '.$this->ref.': '.$reason;
			$credit->note_public = 'ClinicPay refund of bill '.$this->ref;
			$credit->lines = array();
			if ($partial) {
				$vatRate = (float) (isset($this->lines[0]) ? $this->lines[0]['vat_rate'] : 0);
				$ht = price2num($amountTtc / (1 + $vatRate / 100), 'MU');
				$credit->lines[] = array(
					'desc' => 'Refund of bill '.$this->ref.' (partial amount '.$amountTtc.')',
					'subprice' => -1 * $ht,
					'qty' => 1,
					'tva_tx' => $vatRate,
					'localtax1_tx' => 0,
					'localtax2_tx' => 0,
					'fk_product' => 0,
					'remise_percent' => 0,
					'price_base_type' => 'HT',
					'info_bits' => 0,
					'fk_remise_except' => 0,
					'fk_code_ventilation' => 0,
					'fk_parent_line' => 0,
					'special_code' => 0,
					'product_type' => 1,
					'fk_unit' => null,
					'date_start' => null,
					'date_end' => null,
					'ventilation' => 0,
					'subprice_remise' => 0,
					'vat_src_code' => '',
					'ref_ext' => '',
					'origin_id' => null,
					'origin_type' => null,
				);
			} else {
				foreach ($this->lines as $l) {
					$credit->lines[] = array(
						'desc' => $l['label'],
						'subprice' => -1 * price2num($l['price_unit'], 'MU'),
						'qty' => price2num($l['qty'], 'MS'),
						'tva_tx' => (float) $l['vat_rate'],
						'localtax1_tx' => 0,
						'localtax2_tx' => 0,
						'fk_product' => $l['fk_product'] !== null ? (int) $l['fk_product'] : 0,
						'remise_percent' => 0,
						'price_base_type' => 'HT',
						'info_bits' => 0,
						'fk_remise_except' => 0,
						'fk_code_ventilation' => 0,
						'fk_parent_line' => 0,
						'special_code' => 0,
						'product_type' => $l['product_type'],
						'fk_unit' => null,
						'date_start' => null,
						'date_end' => null,
						'ventilation' => 0,
						'subprice_remise' => 0,
						'vat_src_code' => '',
						'ref_ext' => '',
						'origin_id' => null,
						'origin_type' => null,
					);
				}
			}
			$creditId = $credit->create($user);
			if ($creditId <= 0) {
				throw new RuntimeException('credit note create failed: '.($credit->error !== '' ? $credit->error : $this->db->lasterror()));
			}
			$this->refund_draft_id = (int) $creditId;
			patient_audit($this->db, $this->fk_patient, 'CLINICPAY_REFUND', $user, array('op' => 'draft', 'ref' => $this->ref, 'bill' => $this->id, 'credit_note' => (int) $creditId, 'amount' => $partial ? $amountTtc : null, 'reason' => $reason));
			$this->db->commit();
		} catch (ClinicPayRefusedException $e) {
			$this->rollbackAll();
			return -2;
		} catch (Throwable $e) {
			$this->rollbackAll();
			$this->error = $e->getMessage();
			dol_syslog('Paybill::createRefundDraft failed: '.$e->getMessage(), LOG_ERR);
			return -1;
		}
		return 1;
	}

	/**
	 * Execute the pending refund (validate permission, checked by caller).
	 * Double-person red line: the executor must differ from the refund draft
	 * creator unless admin (spec §5.4). Validates the credit note, registers
	 * the refund payment, flips the bill to refunded, audits.
	 *
	 * @param	User	$user	Acting user (validate permission)
	 * @return	int				1 ok, -2 refused (same person / state), -1 error
	 */
	public function executeRefund(User $user)
	{
		$this->error = '';
		if ($this->id <= 0 || $this->fetch($this->id) <= 0) {
			$this->error = 'ClinicPayErrNotPaid';
			return -2;
		}
		if ((int) $this->status === CLINICPAY_BILL_REFUNDED) {
			return 1; // idempotent
		}
		if ((int) $this->status !== CLINICPAY_BILL_PAID || (int) $this->fk_invoice <= 0) {
			$this->error = 'ClinicPayErrNotPaid';
			return -2;
		}
		$draftId = $this->findRefundDraft();
		if ($draftId <= 0) {
			$this->error = 'ClinicPayErrNoRefundDraft';
			return -2;
		}

		require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
		$credit = new Facture($this->db);
		if ($credit->fetch($draftId) <= 0) {
			$this->error = 'ClinicPayErrNoRefundDraft';
			return -2;
		}
		// Note: Facture::fetch() leaves the creator empty in 22.x, read it
		// from the table (llx_facture.fk_user_author) to enforce the
		// two-person red line.
		$sqlCreator = "SELECT fk_user_author AS creator FROM ".$this->db->prefix()."facture WHERE rowid = ".((int) $draftId);
		$resCreator = $this->db->query($sqlCreator);
		if (!$resCreator) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$objCreator = $this->db->fetch_object($resCreator);
		$this->db->free($resCreator);
		$creator = $objCreator ? (int) $objCreator->creator : 0;
		if ($creator === (int) $user->id && empty($user->admin)) {
			$this->error = 'ClinicPayErrSamePerson';
			return -2;
		}

		$this->db->begin();
		try {
			if ($credit->validate($user) <= 0) {
				throw new RuntimeException('credit note validate failed: '.($credit->error !== '' ? $credit->error : $this->db->lasterror()));
			}
			require_once DOL_DOCUMENT_ROOT.'/compta/paiement/class/paiement.class.php';
			$paiement = new Paiement($this->db);
			$paiement->datepaye = dol_now();
			$paiement->amounts = array((int) $credit->id => -1 * price2num($credit->total_ttc, 'MT'));
			$paiement->paiementcode = clinicpay_paiement_code($this->channel !== '' ? $this->channel : CLINICPAY_CHANNEL_CASH);
			$paiement->num_payment = 'REFUND '.$this->ref;
			$paiement->note_public = 'ClinicPay refund of bill '.$this->ref;
			$payId = $paiement->create($user, 1);
			if ($payId < 0) {
				throw new RuntimeException('refund payment failed: '.($paiement->error !== '' ? $paiement->error : $this->db->lasterror()));
			}
			$bankAccount = (int) getDolGlobalInt('CLINICPAY_BANK_ACCOUNT');
			if ($bankAccount > 0) {
				$bankRes = $paiement->addPaymentToBank($user, 'payment_refund', '(PaymentRefund)', $bankAccount, '', '');
				if ($bankRes < 0) {
					throw new RuntimeException('bank write failed: '.($paiement->error !== '' ? $paiement->error : $this->db->lasterror()));
				}
			}

			// V0.2: flip the bill to refunded only when credit notes now cover
			// the full invoice (card refunds credit a partial remaining
			// balance; the bill itself stays paid).
			$origTotal = 0.0;
			$resOrig = $this->db->query("SELECT total_ttc FROM ".$this->db->prefix()."facture WHERE rowid = ".((int) $this->fk_invoice));
			if ($resOrig) {
				$oOrig = $this->db->fetch_object($resOrig);
				$origTotal = $oOrig ? (float) $oOrig->total_ttc : 0.0;
				$this->db->free($resOrig);
			}
			$fullCover = ($this->creditedTotal((int) $this->fk_invoice) + 0.005 >= $origTotal);
			if ($fullCover) {
				$sql = "UPDATE ".$this->db->prefix()."clinicpay_bill SET status = ".CLINICPAY_BILL_REFUNDED;
				$sql .= " WHERE rowid = ".((int) $this->id)." AND status = ".CLINICPAY_BILL_PAID;
				$resql = $this->db->query($sql);
				if (!$resql) {
					throw new RuntimeException($this->db->lasterror());
				}
			}
			patient_audit($this->db, $this->fk_patient, 'CLINICPAY_REFUND', $user, array('op' => 'execute', 'ref' => $this->ref, 'bill' => $this->id, 'credit_note' => (int) $credit->id, 'amount' => price2num($credit->total_ttc, 'MT'), 'full' => $fullCover ? 1 : 0));
			$this->db->commit();
		} catch (Throwable $e) {
			$this->rollbackAll();
			$this->error = $e->getMessage();
			dol_syslog('Paybill::executeRefund failed: '.$e->getMessage(), LOG_ERR);
			return -1;
		}
		$this->status = CLINICPAY_BILL_REFUNDED;
		return 1;
	}

	/**
	 * Pending refund draft (Facture TYPE_CREDIT_NOTE, not yet validated) of
	 * the confirmed bill.
	 *
	 * @return	int	Credit note rowid, 0 if none
	 */
	public function findRefundDraft()
	{
		global $conf;
		if ((int) $this->fk_invoice <= 0) {
			return 0;
		}
		$sql = "SELECT f.rowid FROM ".$this->db->prefix()."facture as f";
		$sql .= " WHERE f.type = ".Facture::TYPE_CREDIT_NOTE." AND f.fk_facture_source = ".((int) $this->fk_invoice);
		$sql .= " AND f.entity = ".((int) $conf->entity)." AND f.fk_statut = 0";
		$sql .= $this->db->order('f.rowid', 'DESC');
		$resql = $this->db->query($sql);
		if (!$resql) {
			return 0;
		}
		$obj = $this->db->fetch_object($resql);
		$this->db->free($resql);
		return $obj ? (int) $obj->rowid : 0;
	}

	/**
	 * Record the tax invoice number (China) that finance wrote back after
	 * issuing the invoice in the tax system. Pure registry: Dolibarr does not
	 * open, number or validate tax invoices, and the native invoice
	 * (llx_facture) is left untouched.
	 *
	 * @param	User		$user		Operator writing the number back
	 * @param	string		$fapiaoNo	Tax invoice number (empty clears it)
	 * @return	int			>0 OK, <=0 KO
	 */
	public function setFapiaoNo(User $user, $fapiaoNo)
	{
		$fapiaoNo = trim((string) $fapiaoNo);

		if ($this->status === CLINICPAY_BILL_DRAFT) {
			$this->error = 'ClinicPayErrFapiaoDraft';
			return -1;
		}
		$sql = "UPDATE ".$this->db->prefix()."clinicpay_bill SET fapiao_no = ";
		$sql .= $fapiaoNo === '' ? "NULL" : "'".$this->db->escape($fapiaoNo)."'";
		$sql .= " WHERE rowid = ".((int) $this->id);
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$this->fapiao_no = $fapiaoNo;

		patient_audit($this->db, $this->fk_patient, 'CLINICPAY_BILL', $user, array('op' => 'fapiao', 'ref' => $this->ref, 'bill' => $this->id, 'fapiao_no' => $fapiaoNo));
		return 1;
	}

	// ================================================================= //
	// Phase 4: charge-bill PDF (spec §3.7)                               //
	// ================================================================= //

	/**
	 * Load patient summary + cashier name for the PDF (spec §3.7 header).
	 *
	 * @return	void
	 */
	public function preparePdfContext()
	{
		$summary = patient_get_summary($this->db, $this->fk_patient);
		if (is_array($summary)) {
			$this->patient_name = isset($summary['name']) ? $summary['name'] : '';
			$this->card_no = isset($summary['card_no']) ? $summary['card_no'] : '';
		}
		$this->cashier_name = '';
		if (!empty($this->fk_user_pay)) {
			require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';
			$u = new User($this->db);
			if ($u->fetch($this->fk_user_pay) > 0) {
				$this->cashier_name = trim($u->lastname.' '.$u->firstname);
				if ($this->cashier_name === '') {
					$this->cashier_name = $u->login;
				}
			}
		}
	}

	/**
	 * Build the charge-bill PDF (model 'sf') through the core generator
	 * lookup (module_parts['models'] = 1, mirroring modPharmacy).
	 *
	 * @param	Translate|null	$outputlangs	Lang
	 * @return	int							1 ok, <0 error (this->error set)
	 */
	public function generateDocument($outputlangs = null)
	{
		global $conf, $langs;

		if (!is_object($outputlangs)) {
			$outputlangs = $langs;
		}
		if (empty($conf->clinicpay->dir_output)) {
			$this->error = 'CLINICPAY_OUTPUTDIR undefined (module not enabled?)';
			return -1;
		}

		$this->preparePdfContext();
		$this->model_pdf = 'sf';

		// The PDF view is a derived artifact; a failure here must not fail
		// the charge itself (the invoice has already been created/validated).
		$result = $this->commonGenerateDocument('core/modules/clinicpay/doc/', 'sf', $outputlangs, 0, 0, 0, null);
		if ($result <= 0) {
			dol_syslog('Paybill::generateDocument failed for '.$this->ref.': '.(is_array($this->errors) ? implode(' / ', $this->errors) : (string) $this->error), LOG_ERR);
			return -1;
		}
		return 1;
	}

	/**
	 * @return	string	Absolute path of the PDF file (may not exist yet)
	 */
	public function pdfPath()
	{
		global $conf;
		$ref = dol_sanitizeFileName($this->ref);
		return (empty($conf->clinicpay->dir_output) ? '' : $conf->clinicpay->dir_output).'/'.$ref.'/'.$ref.'.pdf';
	}

	/**
	 * Validated credit notes total of an invoice (full-credit detection).
	 * facture.total_ttc is stored NEGATIVE for credit notes, so the absolute
	 * value is returned (V0.2 fix: the negative sum silently disabled the
	 * already-refunded guard).
	 *
	 * @param	int	$invoiceId	Native invoice rowid
	 * @return	float				Positive sum of validated credit note totals
	 */
	private function creditedTotal($invoiceId)
	{
		global $conf;
		$sql = "SELECT COALESCE(SUM(total_ttc), 0) AS n FROM ".$this->db->prefix()."facture";
		$sql .= " WHERE type = ".Facture::TYPE_CREDIT_NOTE." AND fk_facture_source = ".((int) $invoiceId);
		$sql .= " AND entity = ".((int) $conf->entity)." AND fk_statut > 0";
		$resql = $this->db->query($sql);
		if (!$resql) {
			throw new RuntimeException($this->db->lasterror());
		}
		$obj = $this->db->fetch_object($resql);
		$this->db->free($resql);
		return $obj ? abs((float) $obj->n) : 0.0;
	}

	/**
	 * fk_soc of the patient's personal thirdparty.
	 *
	 * @param	int	$fkPatient	Patient profile rowid
	 * @return	int				fk_soc (>0), 0 missing, -1 patient not found
	 */
	private function patientSocId($fkPatient)
	{
		$sql = "SELECT fk_soc FROM ".$this->db->prefix()."patient_profile WHERE rowid = ".((int) $fkPatient);
		$resql = $this->db->query($sql);
		if (!$resql) {
			throw new RuntimeException($this->db->lasterror());
		}
		$obj = $this->db->fetch_object($resql);
		$this->db->free($resql);
		if (!$obj) {
			return -1;
		}
		return (int) $obj->fk_soc;
	}

	/**
	 * Snapshot input lines through Product prices and calcul_price_total()
	 * (red line: all amounts go through it).
	 *
	 * @param	array	$input	{fk_product,qty} or {label,price_unit,vat_rate?}
	 * @return	array<int,array>	Snapshot lines with subprice_total (TTC)
	 * @throws RuntimeException
	 */
	private function snapshotLines(array $input)
	{
		$out = array();
		foreach ($input as $raw) {
			if (!is_array($raw)) {
				continue;
			}
			$qty = isset($raw['qty']) ? (float) $raw['qty'] : 0;
			if ($qty <= 0) {
				continue;
			}
			$fkProduct = isset($raw['fk_product']) ? (int) $raw['fk_product'] : 0;
			if ($fkProduct > 0) {
				$p = $this->productSnapshot($fkProduct);
				if ($p === null) {
					throw new RuntimeException('ClinicPayErrProduct');
				}
				$label = $p['label'];
				$pu = $p['price'];
				$vat = $p['tva_tx'];
				$type = $p['product_type'];
			} else {
				$label = trim((string) (isset($raw['label']) ? $raw['label'] : ''));
				if ($label === '') {
					continue;
				}
				$pu = price2num(isset($raw['price_unit']) ? (float) $raw['price_unit'] : 0, 'MU');
				$vat = isset($raw['vat_rate']) ? (float) $raw['vat_rate'] : (float) getDolGlobalString('CLINICPAY_DEFAULT_VAT', '0');
				$type = 1;
			}
			$calc = calcul_price_total($qty, $pu, 0, $vat, 0, 0, 0, 'HT', 0, $type);
			if (!is_array($calc) || count($calc) < 3) {
				throw new RuntimeException('price calculation failed');
			}
			$out[] = array(
				'fk_product' => $fkProduct > 0 ? $fkProduct : null,
				'product_ref' => $fkProduct > 0 ? $p['ref'] : '',
				'label' => $label,
				'qty' => $qty,
				'price_unit' => $pu,
				'vat_rate' => $vat,
				'subprice_total' => (float) $calc[2],
				'product_type' => $type,
				'fk_prescription' => isset($raw['fk_prescription']) && (int) $raw['fk_prescription'] > 0 ? (int) $raw['fk_prescription'] : null,
				'fk_dispense' => isset($raw['fk_dispense']) && (int) $raw['fk_dispense'] > 0 ? (int) $raw['fk_dispense'] : null,
			);
		}
		return $out;
	}

	/**
	 * Product / service price snapshot (spec §1: snapshot the current price).
	 *
	 * @param	int	$fkProduct	Product rowid
	 * @return	array|null		{ref,label,price,tva_tx,product_type} or null
	 */
	private function productSnapshot($fkProduct)
	{
		$sql = "SELECT ref, label, price, tva_tx, fk_product_type FROM ".$this->db->prefix()."product WHERE rowid = ".((int) $fkProduct);
		$resql = $this->db->query($sql);
		if (!$resql) {
			throw new RuntimeException($this->db->lasterror());
		}
		$obj = $this->db->fetch_object($resql);
		$this->db->free($resql);
		if (!$obj) {
			return null;
		}
		return array('ref' => (string) $obj->ref, 'label' => (string) $obj->label, 'price' => (float) $obj->price,
			'tva_tx' => (float) $obj->tva_tx, 'product_type' => (int) $obj->fk_product_type);
	}

	/**
	 * Delete and re-insert draft lines of this bill (drafts only).
	 *
	 * @param	array<int,array>	$lines	Snapshot lines
	 * @return	void
	 * @throws RuntimeException
	 */
	private function replaceLines(array $lines)
	{
		$this->query("DELETE FROM ".$this->db->prefix()."clinicpay_bill_line WHERE fk_bill = ".((int) $this->id));
		$position = 0;
		foreach ($lines as $l) {
			$sql = "INSERT INTO ".$this->db->prefix()."clinicpay_bill_line";
			$sql .= " (fk_bill, position, fk_product, product_ref, label, qty, price_unit, vat_rate, subprice_total, fk_prescription, fk_dispense)";
			$sql .= " VALUES (".((int) $this->id).", ".$position.", ".($l['fk_product'] !== null ? (int) $l['fk_product'] : 'NULL');
			$sql .= ", '".$this->db->escape($l['product_ref'])."', '".$this->db->escape($l['label'])."'";
			$sql .= ", ".price2num($l['qty'], 'MS').", ".price2num($l['price_unit'], 'MU').", ".price2num($l['vat_rate'], 'MU');
			$sql .= ", ".price2num($l['subprice_total'], 'MT').", ".($l['fk_prescription'] !== null ? (int) $l['fk_prescription'] : 'NULL');
			$sql .= ", ".($l['fk_dispense'] !== null ? (int) $l['fk_dispense'] : 'NULL').")";
			$this->query($sql);
			$position++;
		}
	}

	/**
	 * Payment term id (RECEP: immediate, matches a paid clinic charge).
	 *
	 * @return	int
	 */
	private function defaultPaymentTermId()
	{
		$resql = $this->db->query("SELECT rowid FROM ".$this->db->prefix()."c_payment_term WHERE code = 'RECEP' AND active = 1");
		if ($resql) {
			$obj = $this->db->fetch_object($resql);
			$this->db->free($resql);
			if ($obj) {
				return (int) $obj->rowid;
			}
		}
		return 1;
	}

	/**
	 * Native payment mode id of a clinic channel (llx_c_paiement).
	 *
	 * @param	string	$channel	CASH / SCAN
	 * @return	int					0 = unknown (native default)
	 */
	private function paymentModeId($channel)
	{
		$code = clinicpay_paiement_code($channel);
		if ($code === '') {
			return 0;
		}
		$resql = $this->db->query("SELECT id FROM ".$this->db->prefix()."c_paiement WHERE code = '".$this->db->escape($code)."' AND active = 1");
		if ($resql) {
			$obj = $this->db->fetch_object($resql);
			$this->db->free($resql);
			if ($obj) {
				return (int) $obj->id;
			}
		}
		return 0;
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
