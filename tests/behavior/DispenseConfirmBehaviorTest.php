<?php
/* Copyright (C) 2026  modPharmacy contributors
 *
 * Behaviour tests for Dispense::createFromPrescription / confirm / returnSheet.
 *
 * confirm() is the only place stock moves on dispensing, and it must agree
 * with three sources of truth at once: the FEFO batch choice, the
 * llx_stock_mouvement rows, and the batch_note snapshot the traceability page
 * parses back. A source-scanning test cannot see any of that, so it is
 * asserted here against the database. The GSP guard (expired batches never
 * leave the stock) and the return path (reverse per batch, prescription back
 * to issued) live in the same code and get the same treatment.
 *
 * Everything runs inside a transaction the harness rolls back. The DAO opens
 * and commits its own nested transaction; DoliDB counts nesting, so the inner
 * commits only decrement and the outer rollback undoes the work. The demo
 * database is therefore left exactly as it was: the fixture builds its own
 * product / batches / prescription and only reuses a demo patient, the demo
 * warehouse and the admin user.
 */

use ClinicPay\Behavior\BehaviorTestCase;
use ClinicPay\Behavior\BehaviorTestSkip;

require_once __DIR__.'/../../../pharmacy/class/dispense.class.php';
require_once __DIR__.'/../../../prescription/class/prescriptionsheet.class.php';

class DispenseConfirmBehaviorTest extends BehaviorTestCase
{
	/** @var int demo warehouse (WH-MAIN), probed 2026-10-04 */
	const WAREHOUSE_ID = 2;

	/** @var int demo patient, probed 2026-10-04 */
	const PATIENT_ID = 1;

	/** @var int self-increasing suffix so refs stay unique inside one run */
	private static $seq = 0;

	public function setUp()
	{
		if (self::$seq === 0) {
			self::$seq = (int) (microtime(true) * 1000) % 100000000;
		}
	}

	// ------------------------------------------------------------ fixtures

	/**
	 * Build product + stock + batches + an issued prescription, then create
	 * the pending dispense sheet from it. Returns array(dispense, product).
	 *
	 * @param	array<int,array{batch:string,sellby:string,qty:float}>	$batches	batch rows, sellby as 'YYYY-MM-DD'
	 * @param	float|null	$lineQty	prescribed quantity of the single line (default 3)
	 * @return	array{dispense:Dispense,product:int}
	 */
	private function issuedSheet(array $batches, $lineQty = 3.0)
	{
		$this->begin();
		self::$seq++;

		$P = $this->db->prefix();
		$ref = 'TESTD'.self::$seq;

		// Product with batch management on (the dispense flow refuses anything else).
		$sql = "INSERT INTO ".$P."product (entity, ref, label, fk_product_type, tobatch, tosell, tobuy)";
		$sql .= " VALUES (1, '".$ref."', '行为测试品 ".$ref."', 0, 1, 1, 1)";
		$this->assertQuery($sql, 'product insert');
		$productId = (int) $this->db->db->insert_id;

		// One stock row whose reel equals the batch total (the batch rule).
		$reel = 0.0;
		foreach ($batches as $b) {
			$reel += (float) $b['qty'];
		}
		$sql = "INSERT INTO ".$P."product_stock (fk_product, fk_entrepot, reel) VALUES (".$productId.", ".self::WAREHOUSE_ID.", ".$reel.")";
		$this->assertQuery($sql, 'stock insert');
		$stockId = (int) $this->db->db->insert_id;

		foreach ($batches as $b) {
			$sql = "INSERT INTO ".$P."product_lot (entity, fk_product, batch, eatby, sellby)";
			$sql .= " VALUES (1, ".$productId.", '".$this->db->escape($b['batch'])."', '".$b['sellby']."', '".$b['sellby']."')";
			$this->assertQuery($sql, 'lot insert for '.$b['batch']);
			// llx_product_batch.eatby/sellby are datetime, llx_product_lot's are date.
			$sql = "INSERT INTO ".$P."product_batch (fk_product_stock, batch, eatby, sellby, qty)";
			$sql .= " VALUES (".$stockId.", '".$this->db->escape($b['batch'])."', '".$b['sellby']." 00:00:00', '".$b['sellby']." 00:00:00', ".((float) $b['qty']).")";
			$this->assertQuery($sql, 'batch insert for '.$b['batch']);
		}

		// An issued prescription; the sheet lines are attached to the object
		// after fetch (confirm() re-fetches them from the dispense table, so
		// no prescription_line rows are needed).
		$sql = "INSERT INTO ".$P."prescription (entity, ref, presc_type, fk_patient, fk_doctor, date_presc, status, date_issued, fk_user_issue, date_creation)";
		$sql .= " VALUES (1, '".$ref."', 'WM', ".self::PATIENT_ID.", 1, NOW(), ".PRESCRIPTION_STATUS_ISSUED.", NOW(), 1, NOW())";
		$this->assertQuery($sql, 'prescription insert');
		$prescId = (int) $this->db->db->insert_id;

		$presc = new PrescriptionSheet($this->db);
		$this->assertGreaterThan(0, $presc->fetch($prescId), 'prescription fetch failed: '.$presc->error);
		$presc->lines = array(array('fk_product' => $productId, 'product_ref' => $ref, 'label' => '行为测试品', 'qty' => (float) $lineQty, 'qty_unit' => null));

		$dispense = new Dispense($this->db);
		$rc = $dispense->createFromPrescription($this->admin(), $presc, self::WAREHOUSE_ID, 'behaviour test');
		$this->assertSame(1, $rc, 'createFromPrescription failed: '.$dispense->error);

		return array('dispense' => $dispense, 'product' => $productId);
	}

	/**
	 * @return	User
	 */
	private function admin()
	{
		$user = new User($this->db);
		$user->fetch(0, 'admin');
		if ($user->id <= 0) {
			throw new BehaviorTestSkip('no admin user to dispense with');
		}
		return $user;
	}

	/**
	 * @param	string	$sql	INSERT to run, the test fails on error
	 * @param	string	$what	short name for the message
	 * @return	void
	 */
	private function assertQuery($sql, $what)
	{
		$resql = $this->db->query($sql);
		if (!$resql) {
			throw new \Exception($what.' failed: '.$this->db->lasterror());
		}
	}

	/**
	 * Product stock summed over its stock rows in the test warehouse.
	 *
	 * @param	int	$product
	 * @return	float
	 */
	private function productReel($product)
	{
		return $this->sqlSum('product_stock', 'fk_product = '.(int) $product.' AND fk_entrepot = '.(int) self::WAREHOUSE_ID, 'reel');
	}

	/**
	 * Qty of one batch of one product in the test warehouse.
	 *
	 * @param	int		$product
	 * @param	string	$batch
	 * @return	float
	 */
	private function batchQtyOf($product, $batch)
	{
		$sql = "SELECT COALESCE(SUM(pb.qty), 0) AS q";
		$sql .= " FROM ".$this->db->prefix()."product_batch AS pb";
		$sql .= " INNER JOIN ".$this->db->prefix()."product_stock AS ps ON ps.rowid = pb.fk_product_stock";
		$sql .= " WHERE ps.fk_product = ".(int) $product." AND ps.fk_entrepot = ".(int) self::WAREHOUSE_ID." AND pb.batch = '".$this->db->escape($batch)."'";
		$resql = $this->db->query($sql);
		$q = $resql ? (float) $this->db->fetch_object($resql)->q : 0.0;
		if ($resql) {
			$this->db->free($resql);
		}
		return $q;
	}

	/**
	 * Outbound (type 2) movements tagged with the dispense ref.
	 *
	 * @param	string	$ref	dispense ref
	 * @return	array<int,object>	rows: batch, value
	 */
	private function outboundMovements($ref)
	{
		return $this->taggedMovements('Dispense '.$ref);
	}

	/**
	 * Inbound (type 0) movements tagged with the return ref.
	 *
	 * @param	string	$ref	dispense ref
	 * @return	array<int,object>
	 */
	private function returnMovements($ref)
	{
		return $this->taggedMovements('Return '.$ref);
	}

	/**
	 * @param	string	$label	exact label match
	 * @return	array<int,object>	rows: batch, value
	 */
	private function taggedMovements($label)
	{
		$sql = "SELECT batch, value FROM ".$this->db->prefix()."stock_mouvement";
		$sql .= " WHERE label = '".$this->db->escape($label)."' ORDER BY rowid ASC";
		$resql = $this->db->query($sql);
		$rows = array();
		if ($resql) {
			while ($o = $this->db->fetch_object($resql)) {
				$rows[] = $o;
			}
			$this->db->free($resql);
		}
		return $rows;
	}

	/**
	 * The prescription status straight from the table.
	 *
	 * @param	int	$prescId
	 * @return	int
	 */
	private function prescriptionStatus($prescId)
	{
		return (int) $this->needColumn('prescription', $prescId, 'status');
	}

	// ------------------------------------------------------------ tests

	/**
	 * FEFO must take the earliest sell-by batch first, spill into the next
	 * one, and record both in batch_note (which the traceability page parses
	 * back). The reel, the batch rows and the movements must all agree.
	 */
	public function testConfirmAllocatesFefoAcrossBatchesAndWritesBatchNote()
	{
		$day = (int) time();
		$f = $this->issuedSheet(array(
			array('batch' => 'B-LATE', 'sellby' => date('Y-m-d', $day + 300 * 86400), 'qty' => 10.0),
			array('batch' => 'B-EARLY', 'sellby' => date('Y-m-d', $day + 100 * 86400), 'qty' => 5.0),
		), 6.0);
		$dispense = $f['dispense'];
		$product = $f['product'];

		$this->assertSame(1, $dispense->confirm($this->admin()), 'confirm failed: '.$dispense->error);
		$this->assertSame(PharmacyDispenseStatus::DISPENSED, (int) $dispense->status, 'the sheet must be dispensed');

		// FEFO: 5 from the early batch, then 1 from the late one.
		$this->assertEquals(9.0, $this->productReel($product), 'the reel must drop by the dispensed 6');
		$this->assertEquals(0.0, $this->batchQtyOf($product, 'B-EARLY'), 'the early batch must be emptied first');
		$this->assertEquals(9.0, $this->batchQtyOf($product, 'B-LATE'), 'the late batch must only cover the remainder');

		// batch_note: earliest sell-by batch first, ISO dates (parsed back by trace_batch.php).
		$note = $dispense->lines[0]['batch_note'];
		$this->assertTrue(is_string($note) && $note !== '', 'confirm must write the batch_note snapshot');
		$this->assertTrue(strpos($note, 'B-EARLY') !== false, 'the note must name the first-picked batch: '.$note);
		$this->assertGreaterThan(
			strpos($note, 'B-EARLY'),
			strpos($note, 'B-LATE'),
			'FEFO order must show in the note: earliest sell-by batch first, got '.$note
		);

		// Movements: outbound, per batch, matching the allocation.
		$mv = $this->outboundMovements($dispense->ref);
		$this->assertCount(2, $mv, 'one outbound movement per allocated batch is expected');
		$sum = 0.0;
		$byBatch = array();
		foreach ($mv as $m) {
			$sum += (float) $m->value;
			$byBatch[$m->batch] = (float) $m->value;
		}
		$this->assertEquals(-6.0, $sum, 'the movements must total the dispensed quantity');
		$this->assertEquals(-5.0, isset($byBatch['B-EARLY']) ? $byBatch['B-EARLY'] : 0.0, 'the early batch movement is missing');
		$this->assertEquals(-1.0, isset($byBatch['B-LATE']) ? $byBatch['B-LATE'] : 0.0, 'the late batch movement is missing');

		// The prescription bridge: issued -> dispensed.
		$this->assertSame(PRESCRIPTION_STATUS_DISPENSED, $this->prescriptionStatus($dispense->fk_prescription), 'the prescription must be marked dispensed');
	}

	/**
	 * Confirming an already-dispensed sheet must be a no-op: the second call
	 * returns 1 and moves no further stock.
	 */
	public function testConfirmIsIdempotent()
	{
		$f = $this->issuedSheet(array(
			array('batch' => 'B-1', 'sellby' => date('Y-m-d', time() + 365 * 86400), 'qty' => 10.0),
		));
		$dispense = $f['dispense'];
		$product = $f['product'];

		$this->assertSame(1, $dispense->confirm($this->admin()), 'first confirm failed: '.$dispense->error);
		$reelAfterFirst = $this->productReel($product);
		$mvAfterFirst = $this->outboundMovements($dispense->ref);

		$this->assertSame(1, $dispense->confirm($this->admin()), 'a repeat confirm must return 1 (idempotent)');
		$this->assertEquals($reelAfterFirst, $this->productReel($product), 'the repeat confirm must not move stock again');
		$this->assertCount(count($mvAfterFirst), $this->outboundMovements($dispense->ref), 'the repeat confirm must not write more movements');
	}

	/**
	 * GSP red line: an expired batch must never leave the stock, even when
	 * FEFO would normally pick it first (earliest sell-by).
	 */
	public function testConfirmSkipsExpiredBatch()
	{
		$day = (int) time();
		$f = $this->issuedSheet(array(
			array('batch' => 'B-EXPIRED', 'sellby' => date('Y-m-d', $day - 86400), 'qty' => 50.0),
			array('batch' => 'B-VALID', 'sellby' => date('Y-m-d', $day + 200 * 86400), 'qty' => 5.0),
		));
		$dispense = $f['dispense'];
		$product = $f['product'];

		$this->assertSame(1, $dispense->confirm($this->admin()), 'confirm failed: '.$dispense->error);

		// FEFO would have picked the expired batch (earliest sell-by); the
		// guard must skip it and allocate from the valid one instead.
		$this->assertEquals(50.0, $this->batchQtyOf($product, 'B-EXPIRED'), 'the expired batch must not be touched');
		$this->assertEquals(2.0, $this->batchQtyOf($product, 'B-VALID'), 'the valid batch must cover the dispense');

		$note = $dispense->lines[0]['batch_note'];
		$this->assertTrue(strpos((string) $note, 'B-VALID') !== false, 'the note must name the batch actually shipped: '.$note);
		$this->assertTrue(strpos((string) $note, 'B-EXPIRED') === false, 'the note must never name an expired batch: '.$note);
	}

	/**
	 * When the only batches left are expired, the refusal must say so
	 * (PharmacyErrExpiryOnly) instead of a misleading plain shortage, and
	 * nothing may move.
	 */
	public function testConfirmWithOnlyExpiredBatchRefusesAsExpiryOnly()
	{
		$f = $this->issuedSheet(array(
			array('batch' => 'B-EXPIRED', 'sellby' => date('Y-m-d', time() - 86400), 'qty' => 50.0),
		));
		$dispense = $f['dispense'];

		$rc = $dispense->confirm($this->admin());
		$this->assertSame(-1, $rc, 'confirm must refuse, got '.$rc);
		$this->assertSame('PharmacyErrExpiryOnly', $dispense->error, 'the refusal must be the expiry-only error, got '.$dispense->error);
		// The failure path rolls the whole transaction nest back (fixture
		// included), so the fixture rows are gone here: "stock untouched" is
		// guaranteed structurally by the catch-everything rollback, and the
		// meaningful committed-state assertion is that no movement exists.
		$this->assertCount(0, $this->outboundMovements($dispense->ref), 'a refused confirm must leave no committed movement');
	}

	/**
	 * Asking for more than the reel holds must fail closed with the plain
	 * shortage error, and nothing may move.
	 */
	public function testConfirmShortageFailsClosed()
	{
		// Only 4 on the shelf, prescribed 10.
		$f = $this->issuedSheet(array(
			array('batch' => 'B-1', 'sellby' => date('Y-m-d', time() + 365 * 86400), 'qty' => 4.0),
		), 10.0);
		$dispense = $f['dispense'];

		$rc = $dispense->confirm($this->admin());
		$this->assertSame(-1, $rc, 'confirm must refuse, got '.$rc);
		$this->assertSame('PharmacyErrStockShort', $dispense->error, 'the refusal must be the plain shortage error, got '.$dispense->error);
		$this->assertCount(0, $this->outboundMovements($dispense->ref), 'a refused confirm must leave no movement');
	}

	/**
	 * A return must reverse the outbound movements batch by batch, restore
	 * the prescription to issued and keep the sheet as the trace (status 9).
	 */
	public function testReturnSheetRestoresBatchesAndPrescription()
	{
		$day = (int) time();
		$f = $this->issuedSheet(array(
			array('batch' => 'B-LATE', 'sellby' => date('Y-m-d', $day + 300 * 86400), 'qty' => 10.0),
			array('batch' => 'B-EARLY', 'sellby' => date('Y-m-d', $day + 100 * 86400), 'qty' => 5.0),
		), 6.0);
		$dispense = $f['dispense'];
		$product = $f['product'];
		$this->assertSame(1, $dispense->confirm($this->admin()), 'confirm failed: '.$dispense->error);
		$reelAfterConfirm = $this->productReel($product);

		$this->assertSame(1, $dispense->returnSheet($this->admin(), '患者退药'), 'return failed: '.$dispense->error);
		$this->assertSame(PharmacyDispenseStatus::RETURNED, (int) $dispense->status, 'the sheet must be returned');

		// Stock restored per batch: the early batch gets its 5 back, the late one its 1.
		$this->assertEquals(15.0, $this->productReel($product), 'the return must restore the reel');
		$this->assertEquals(5.0, $this->batchQtyOf($product, 'B-EARLY'), 'the early batch qty must be restored');
		$this->assertEquals(10.0, $this->batchQtyOf($product, 'B-LATE'), 'the late batch qty must be restored');
		$this->assertEquals($reelAfterConfirm + 6.0, $this->productReel($product), 'sanity: reel moved back by the returned quantity');

		// Inbound movements per batch, tagged with the ref.
		$mv = $this->returnMovements($dispense->ref);
		$sum = 0.0;
		foreach ($mv as $m) {
			$sum += (float) $m->value;
		}
		$this->assertEquals(6.0, $sum, 'the return movements must total the returned quantity');
		$this->assertCount(2, $mv, 'one inbound movement per returned batch is expected');

		// The prescription is issued again, so the sheet can be redone.
		$this->assertSame(PRESCRIPTION_STATUS_ISSUED, $this->prescriptionStatus($dispense->fk_prescription), 'the prescription must be issued again');

		// The sheet stays as the trace; a second return is refused.
		$again = $dispense->returnSheet($this->admin(), '再退一次');
		$this->assertSame(-2, $again, 'a second return must be refused, got '.$again);
	}

	/**
	 * A return without a reason must be refused: the trace without a why is
	 * worse than no trace.
	 */
	public function testReturnRequiresReason()
	{
		$f = $this->issuedSheet(array(
			array('batch' => 'B-1', 'sellby' => date('Y-m-d', time() + 365 * 86400), 'qty' => 10.0),
		));
		$dispense = $f['dispense'];
		$product = $f['product'];
		$this->assertSame(1, $dispense->confirm($this->admin()), 'confirm failed: '.$dispense->error);
		$reelAfterConfirm = $this->productReel($product);

		$rc = $dispense->returnSheet($this->admin(), '   ');
		$this->assertSame(-1, $rc, 'an empty reason must be refused, got '.$rc);
		$this->assertSame('PharmacyErrReturnReasonRequired', $dispense->error, 'the refusal must name the missing reason');
		$this->assertEquals($reelAfterConfirm, $this->productReel($product), 'the refused return must not touch the stock');
	}
}

/**
 * Status mirror of PHARMACY_STATUS_* (pharmacy.lib.php), kept local so the
 * suite can be read without loading the module constants.
 */
class PharmacyDispenseStatus
{
	const PENDING = 0;
	const DISPENSED = 1;
	const RETURNED = 9;
}
